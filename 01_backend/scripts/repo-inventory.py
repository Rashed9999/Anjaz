#!/usr/bin/env python3
"""Read-only static evidence for AMIAL_SYSTEM_MAP; not a runtime verifier.

Comments are tokenized away, route groups retain prefixes/names/middleware,
and controller aliases/variables are resolved. Dynamic expressions remain
explicit candidates. No file is classified DEAD or deleted by this tool.
Use --write to regenerate the machine-readable evidence and screen inventory.
"""

import argparse
from collections import Counter, defaultdict
from dataclasses import dataclass
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]


@dataclass
class Token:
    value: str
    kind: str
    line: int


def tokens(source):
    """Small lexical scanner, not a PHP/Dart grammar or compiler."""
    out, i, line = [], 0, 1
    while i < len(source):
        start, first_line = i, line
        char = source[i]
        if char.isspace():
            i += 1
        elif source.startswith("//", i) or char == "#":
            end = source.find("\n", i)
            i = len(source) if end == -1 else end
        elif source.startswith("/*", i):
            end = source.find("*/", i + 2)
            i = len(source) if end == -1 else end + 2
        elif char in "\"'`":
            quote = char * 3 if source.startswith(char * 3, i) else char
            i += len(quote)
            while i < len(source):
                if source[i] == "\\":
                    i += 2
                elif source.startswith("${", i):
                    # Preserve nested quoted map keys in Dart interpolation.
                    depth, i = 1, i + 2
                    while i < len(source) and depth:
                        if source[i] in "\"'":
                            inner, i = source[i], i + 1
                            while i < len(source) and source[i] != inner:
                                i += 2 if source[i] == "\\" else 1
                        elif source[i] == "{":
                            depth += 1
                        elif source[i] == "}":
                            depth -= 1
                        i += 1
                elif source.startswith(quote, i):
                    i += len(quote)
                    break
                else:
                    i += 1
            value = source[start + len(quote):i - len(quote)]
            value = value.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\")
            out.append(Token(value, "string", first_line))
        else:
            match = re.match(r"\$?[A-Za-z_\\][A-Za-z0-9_\\]*|[0-9]+|::|->|=>|===|==|!=", source[i:])
            value = match.group() if match else char
            out.append(Token(value, "code", first_line))
            i += len(value)
        line += source[start:i].count("\n")
    return out


def paired(ts):
    pairs, stack = {}, []
    for i, t in enumerate(ts):
        if t.kind == "string":
            continue
        if t.value in ("(", "[", "{"):
            stack.append(i)
        elif t.value in (")", "]", "}") and stack:
            opening = stack.pop()
            pairs[opening] = i
    return pairs


def split(ts, separator=","):
    result, start, depth = [], 0, 0
    for i, t in enumerate(ts):
        if t.kind == "code":
            if t.value in ("(", "[", "{"):
                depth += 1
            elif t.value in (")", "]", "}"):
                depth -= 1
            elif depth == 0 and t.value == separator:
                result.append(ts[start:i])
                start = i + 1
    result.append(ts[start:])
    return result


def evaluate(ts, env, aliases=None):
    if not ts:
        return ""
    aliases = aliases or {}
    if ts[0].value == "[" and ts[-1].value == "]":
        chunks = split(ts[1:-1])
        if any(any(t.value == "=>" for t in chunk) for chunk in chunks):
            result = {}
            for chunk in chunks:
                sides = split(chunk, "=>")
                if len(sides) == 2:
                    result[str(evaluate(sides[0], env, aliases))] = evaluate(sides[1], env, aliases)
            return result
        return [evaluate(chunk, env, aliases) for chunk in chunks if chunk]
    pieces = split(ts, ".")
    if len(pieces) > 1:
        return "".join(str(evaluate(piece, env, aliases)) for piece in pieces)
    if len(ts) == 1:
        token = ts[0]
        if token.kind == "string":
            return interpolate(token.value, env)
        return env.get(token.value, "?" + token.value)
    if len(ts) == 3 and ts[1].value == "::":
        name = ts[0].value.lstrip("\\")
        name = aliases.get(name, name)
        if ts[2].value == "class":
            return name
        return env.get(name + "::" + ts[2].value, "?" + name + "::" + ts[2].value)
    return "?dynamic:" + "".join(t.value for t in ts)[:120]


def interpolate(value, env):
    """Keep nested Dart expressions opaque, including quoted map keys."""
    out, i = [], 0
    while i < len(value):
        if value.startswith("${", i):
            start, depth, i = i + 2, 1, i + 2
            while i < len(value) and depth:
                if value[i] in "\"'":
                    quote, i = value[i], i + 1
                    while i < len(value) and value[i] != quote:
                        i += 2 if value[i] == "\\" else 1
                elif value[i] == "{":
                    depth += 1
                elif value[i] == "}":
                    depth -= 1
                i += 1
            out.append(str(env.get(value[start:i - 1].strip(), "{dynamic}")))
        else:
            match = re.match(r"\{\$(\w+)\}|\$(\w+)", value[i:])
            if match:
                out.append(str(env.get(match.group(1) or match.group(2), "{dynamic}")))
                i += len(match.group())
            else:
                out.append(value[i])
                i += 1
    return "".join(out)


def public_methods(symbols):
    # PHP methods with no visibility modifier are public by default.
    methods = []
    for match in re.finditer(r"(?:(public|protected|private)\s+)?(?:static\s+)?function\s+&?\s*(\w+)", symbols):
        if match.group(1) not in ("private", "protected"):
            methods.append(match.group(2))
    return methods


def imports(ts):
    result = {}
    for i, token in enumerate(ts):
        if token.value != "use" or i + 1 >= len(ts) or ts[i + 1].kind != "code":
            continue
        name = ts[i + 1].value.lstrip("\\")
        if "\\" not in name:
            continue
        alias = ts[i + 3].value if i + 3 < len(ts) and ts[i + 2].value == "as" else name.split("\\")[-1]
        result[alias] = name
    return result


class Routes:
    def __init__(self, root):
        self.root, self.rows, self.loaded, self.constants = root, [], set(), {}
        for path in (root / "01_backend/app/Support/Access").glob("*.php"):
            text = path.read_text()
            ns = re.search(r"namespace\s+([^;]+)", text)
            name = f"{ns.group(1)}\\{path.stem}" if ns else path.stem
            for key, value in re.findall(r"const\s+(\w+)\s*=\s*'([^']*)'", text):
                self.constants[name + "::" + key] = value

    def read(self, relative, context=None, loaded=True):
        context = context or {"prefix": "", "name": "", "middleware": [], "namespace": ""}
        if loaded and relative.startswith("01_backend/routes/"):
            self.loaded.add(relative)
        ts = tokens((self.root / relative).read_text())
        pairs, aliases = paired(ts), imports(ts)

        def walk(lo, hi, ctx, environment):
            env, i = dict(environment), lo
            while i < hi:
                if i + 5 < hi and ts[i].value.startswith("$") and ts[i + 1].value == "=" and ts[i + 3].value == "::" and ts[i + 4].value == "class":
                    env[ts[i].value] = evaluate(ts[i + 2:i + 5], env, aliases)
                if i + 3 >= hi or ts[i].value not in ("Route", "\\Illuminate\\Support\\Facades\\Route") or ts[i + 1].value != "::":
                    i += 1
                    continue
                start, cursor, chain = i, i + 2, []
                while cursor + 1 < hi and ts[cursor + 1].value == "(" and cursor + 1 in pairs:
                    close = pairs[cursor + 1]
                    chain.append((ts[cursor].value, cursor + 2, close))
                    cursor = close + 1
                    if cursor < hi and ts[cursor].value == "->":
                        cursor += 1
                    else:
                        break
                if not chain:
                    i += 1
                    continue
                local = {**ctx, "middleware": list(ctx["middleware"])}
                for method, a, b in chain:
                    if method not in ("prefix", "name", "middleware", "namespace", "withoutMiddleware"):
                        continue
                    value = evaluate(ts[a:b], env, aliases)
                    if method in ("middleware", "withoutMiddleware"):
                        values = value if isinstance(value, list) else [value]
                        if method == "middleware":
                            local["middleware"].extend(values)
                        else:
                            local["middleware"] = [m for m in local["middleware"] if m not in values]
                    elif method == "name":
                        local["name"] += str(value)
                    elif method == "namespace":
                        local["namespace"] = (local["namespace"].rstrip("\\") + "\\" + str(value)).strip("\\")
                    else:
                        local["prefix"] = (local["prefix"].strip("/") + "/" + str(value).strip("/")).strip("/")
                for method, a, b in chain:
                    args = split(ts[a:b])
                    if method == "group":
                        if args and args[0] and args[0][0].value == "[":
                            options = evaluate(args[0], env, aliases)
                            if isinstance(options, dict):
                                for key, value in options.items():
                                    if key == "prefix":
                                        local["prefix"] = (local["prefix"].strip("/") + "/" + str(value).strip("/")).strip("/")
                                    elif key in ("as", "name"):
                                        local["name"] += str(value)
                                    elif key == "namespace":
                                        local["namespace"] = (local["namespace"].rstrip("\\") + "\\" + str(value)).strip("\\")
                                    elif key == "middleware":
                                        local["middleware"].extend(value if isinstance(value, list) else [value])
                        bodies = [k for k in range(a, b) if ts[k].value == "{" and ts[k].kind == "code"]
                        if bodies:
                            body = bodies[0]
                            walk(body + 1, pairs.get(body, b), local, env)
                        else:
                            for token in ts[a:b]:
                                if token.kind == "string" and token.value.startswith("routes/"):
                                    self.read("01_backend/" + token.value, local, loaded)
                    elif method in ("get", "post", "put", "patch", "delete", "options", "any", "match", "view", "redirect", "permanentRedirect"):
                        verbs = [method.upper()]
                        if method == "match":
                            verbs, args = evaluate(args[0], env, aliases), args[1:]
                            verbs = [v.upper() for v in verbs]
                        if method in ("view", "redirect", "permanentRedirect"):
                            verbs = ["GET"]
                        uri = str(evaluate(args[0], env, aliases)) if args else "?dynamic"
                        action = evaluate(args[1], env, aliases) if len(args) > 1 else "?missing"
                        controller, handler = None, None
                        if isinstance(action, list) and len(action) == 2:
                            controller, handler = action
                        elif isinstance(action, str) and "@" in action and not action.startswith("?"):
                            controller, handler = action.split("@", 1)
                            if not controller.startswith("App\\"):
                                controller = local["namespace"] + "\\" + controller
                        elif isinstance(action, str) and action.startswith("App\\") and method not in ("view", "redirect", "permanentRedirect"):
                            controller, handler = action, "__invoke"
                        if controller:
                            controller = str(controller).lstrip("\\")
                        for verb in verbs:
                            self.rows.append({
                                "method": verb, "uri": "/" + (local["prefix"].strip("/") + "/" + uri.lstrip("/")).strip("/"),
                                "name": local["name"], "middleware": local["middleware"],
                                "controller": controller, "handler": handler,
                                "action_kind": method if method in ("view", "redirect", "permanentRedirect") else ("controller" if controller else "closure_or_dynamic"),
                                "source": relative, "line": ts[start].line,
                                "registered_from_bootstrap": loaded,
                            })
                i = max(cursor, i + 1)

        walk(0, len(ts), context, self.constants)


def normalize_uri(uri):
    uri = uri.split("?", 1)[0].rstrip("/") or "/"
    return re.sub(r"\{[^}]+\}", "{}", uri)


def build(root):
    listing = subprocess.check_output(["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd=root)
    files = sorted(set(listing.decode().split("\0")) - {""})
    codefiles = [p for p in files if (p.startswith("01_backend/") and p.endswith(".php") and "/vendor/" not in p)
                 or (p.startswith("02_flutter_app/lib/") and p.endswith(".dart"))]
    corpus = {p: (root / p).read_text() for p in codefiles if (root / p).is_file()}
    lexical = {p: tokens(src) for p, src in corpus.items()}
    scanner = Routes(root)
    entries = ["01_backend/bootstrap/app.php", "01_backend/app/Providers/RouteServiceProvider.php"]
    provider_tokens = tokens((root / "01_backend/bootstrap/providers.php").read_text())
    for token in provider_tokens:
        if token.kind == "code" and token.value.startswith("App\\Providers\\"):
            entry = "01_backend/app/" + token.value[4:].replace("\\", "/") + ".php"
            if entry not in entries and (root / entry).is_file():
                entries.append(entry)
    for entry in entries:
        scanner.read(entry)
    for path in sorted((root / "01_backend/routes").rglob("*.php")):
        relative = path.relative_to(root).as_posix()
        if relative not in scanner.loaded and path.name not in ("console.php", "channels.php"):
            scanner.read(relative, loaded=False)

    dependencies, class_index, symbols = {}, {}, {}
    for relative, ts in lexical.items():
        symbols[relative] = " ".join(t.value for t in ts if t.kind == "code")
        if relative.startswith("01_backend/app/"):
            deps = []
            for full in imports(ts).values():
                if full.startswith("App\\"):
                    target = "01_backend/app/" + full[4:].replace("\\", "/") + ".php"
                    deps.append(target)
            dependencies[relative] = sorted(set(deps))
            namespace = re.search(r"\bnamespace\s+([^;]+);", corpus[relative])
            for match in re.finditer(r"\bclass\s+(\w+)(?:\s+extends\s+([\w\\]+))?", symbols[relative]):
                full = ((namespace.group(1) + "\\") if namespace else "") + match.group(1)
                class_index[full] = {"file": relative, "parent": match.group(2),
                                     "methods": public_methods(symbols[relative])}
    route_gaps = []
    for row in scanner.rows:
        if not row["controller"]:
            continue
        info = class_index.get(row["controller"])
        row["controller_file"] = info["file"] if info else None
        row["handler_declared_public"] = bool(info and row["handler"] in info["methods"])
        if not info or not row["handler_declared_public"]:
            route_gaps.append(row)

    dart = {p: ts for p, ts in lexical.items() if p.startswith("02_flutter_app/lib/")}
    dart_imports, missing_imports, calls, screens = {}, [], [], []
    for relative, ts in dart.items():
        imported, constants = [], {}
        for i, t in enumerate(ts):
            if t.value in ("import", "export", "part") and i + 1 < len(ts) and ts[i + 1].kind == "string":
                uri = ts[i + 1].value
                if uri.startswith("package:amial_pay/"):
                    path = "02_flutter_app/lib/" + uri[len("package:amial_pay/"):]
                elif ":" not in uri:
                    path = (root / relative).parent.joinpath(uri).resolve().relative_to(root).as_posix()
                else:
                    continue
                imported.append(path)
                if not (root / path).is_file():
                    missing_imports.append({"file": relative, "line": t.line, "target": path})
            if i + 2 < len(ts) and ts[i + 1].value == "=" and ts[i + 2].kind == "string":
                constants[t.value] = evaluate(ts[i + 2:i + 3], {})
        dart_imports[relative] = sorted(set(imported))
        for i, t in enumerate(ts):
            if t.value in ("getData", "postData", "putData", "deleteData", "postMultipartData") and i + 2 < len(ts) and ts[i + 1].value == "(":
                argument = ts[i + 2]
                if argument.kind == "string":
                    uri = evaluate([argument], constants)
                else:
                    uri = constants.get(argument.value, "?dynamic:" + argument.value)
                method = {"getData": "GET", "postData": "POST", "putData": "PUT", "deleteData": "DELETE", "postMultipartData": "POST"}[t.value]
                calls.append({"file": relative, "line": t.line, "method": method, "uri": uri})
        for i, t in enumerate(ts[:-2]):
            if (t.value == "class" and ts[i + 1].value.endswith("Screen")
                    and i + 3 < len(ts) and ts[i + 2].value == "extends"
                    and ts[i + 3].value in ("StatelessWidget", "StatefulWidget", "GetView")):
                cls = ts[i + 1].value
                if cls.startswith("_"):
                    continue
                pattern = re.compile(r"(?<![\w])" + re.escape(cls) + r"\s*\(")
                consumers = [p for p, body in symbols.items() if p in dart and p != relative and pattern.search(body)]
                own = pattern.findall(symbols[relative])
                status = "NESTED" if consumers or len(own) > 1 else "ORPHAN_CANDIDATE"
                screens.append({"screen": cls, "file": relative, "line": t.line, "module": relative.split('/')[3] if '/features/' in relative else "shell",
                                "navigation_candidates": consumers, "status": status, "runtime_verified": False})

    active = [r for r in scanner.rows if r["registered_from_bootstrap"]]
    endpoints = {(r["method"], normalize_uri(r["uri"])) for r in active if "?dynamic" not in r["uri"]}
    for call in calls:
        uri = call["uri"]
        if not uri.startswith("/") or "?dynamic" in uri:
            call["status"] = "DYNAMIC_UNRESOLVED"
        else:
            key = (call["method"], normalize_uri(uri))
            if key in endpoints or ("ANY", key[1]) in endpoints:
                call["status"] = "STATIC_PATTERN_MATCH" if "{dynamic}" in uri else "STATIC_MATCH"
            else:
                call["status"] = "DYNAMIC_UNRESOLVED" if "{dynamic}" in uri else "MISSING_API_CANDIDATE"
    for screen in screens:
        screen["api_calls"] = [c for c in calls if c["file"] == screen["file"]]
        screen["imports"] = dart_imports.get(screen["file"], [])
    duplicate_routes = defaultdict(list)
    for row in active:
        if "?dynamic" not in row["uri"]:
            duplicate_routes[row["method"] + " " + normalize_uri(row["uri"])].append(row)
    route_names = defaultdict(list)
    for row in active:
        if row["name"]:
            route_names[row["name"]].append(row)
    same_source = defaultdict(list)
    for path, body in corpus.items():
        if path.startswith("01_backend/app/") or path.startswith("02_flutter_app/lib/"):
            same_source[hashlib.sha256(body.encode()).hexdigest()].append(path)
    migrations = {}
    for relative, ts in lexical.items():
        if "/database/migrations/" in relative:
            names = []
            for i in range(len(ts) - 4):
                if [t.value for t in ts[i:i + 4]] in (["Schema", "::", "create", "("], ["Schema", "::", "table", "("]):
                    if ts[i + 4].kind == "string":
                        names.append(ts[i + 4].value)
            migrations[relative] = sorted(set(names))
    return {
        "baseline_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root).decode().strip(),
        "evidence_level": "STATIC_ONLY; references are candidates, never proof of an executed journey",
        "limitations": ["No PHP/Flutter runtime", "Controller inherited methods and conditional provider registration need manual review", "Only directly derived Widget classes ending Screen are counted; differently named widgets are outside this metric", "Dynamic route/URL expressions are unresolved or pattern matches, not executed requests", "Imports show dependencies, not runtime reachability", "No deletion based on this inventory"],
        "counts": {"tracked_and_untracked_files": len(files), "backend_controllers": sum(p.startswith("01_backend/app/Http/Controllers/") for p in corpus),
                   "backend_services": sum(p.startswith("01_backend/app/Services/") for p in corpus),
                   "backend_models": sum(p.startswith("01_backend/app/Models/") for p in corpus),
                   "migrations": len(migrations), "table_names_referenced_by_migrations": len({t for v in migrations.values() for t in v}),
                   "blade_templates": sum(p.endswith(".blade.php") for p in files), "flutter_library_files": len(dart),
                   "screen_classes": len(screens), "route_declarations_expanded_by_method": len(scanner.rows),
                   "loaded_route_declarations": len(active), "api_call_sites": len(calls)},
        "loaded_route_files": sorted(scanner.loaded), "routes": scanner.rows, "controller_action_candidates": route_gaps,
        "duplicate_route_candidates": {k: v for k, v in duplicate_routes.items() if len(v) > 1},
        "duplicate_route_name_candidates": {k: v for k, v in route_names.items() if len({(r['source'], r['line']) for r in v}) > 1},
        "php_dependencies": dependencies, "dart_imports": dart_imports, "missing_dart_imports": missing_imports,
        "screens": screens, "api_calls": calls, "migrations": migrations,
        "identical_source_candidates": [v for v in same_source.values() if len(v) > 1],
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write", action="store_true")
    args = parser.parse_args()
    result = build(ROOT)
    print(json.dumps(result["counts"], indent=2))
    print("Controller/action candidates:", len(result["controller_action_candidates"]))
    print("Missing imports:", len(result["missing_dart_imports"]))
    print("API call classification:", dict(Counter(c["status"] for c in result["api_calls"])))
    print("Screen classification:", dict(Counter(s["status"] for s in result["screens"])))
    if args.write:
        output = ROOT / "docs/AMIAL_STATIC_INVENTORY.json"
        output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
        rows = ["# جرد شاشات أميال — أدلة ساكنة", "", "> المراجع مداخل مرشحة وليست إثبات ضغط زر. الحقول الديناميكية تتطلب تشغيل التطبيق. لا حذف آلي.", "",
                "| الشاشة | الوحدة | الملف | المداخل المرشحة | نداءات API المباشرة | التصنيف |", "|---|---|---|---|---|---|"]
        for s in result["screens"]:
            navigation = "<br>".join(f"`{p}`" for p in s["navigation_candidates"])
            apis = "<br>".join(f"`{c['method']} {c['uri']}`" for c in s["api_calls"])
            rows.append(f"| {s['screen']} | {s['module']} | `{s['file']}` | {navigation or 'لا مرجع مباشر مقاس'} | {apis or 'لا نداء مباشر؛ افحص controller/repository المستورد'} | {s['status']} |")
        (ROOT / "docs/AMIAL_SCREEN_INVENTORY.md").write_text("\n".join(rows) + "\n")


if __name__ == "__main__":
    main()
