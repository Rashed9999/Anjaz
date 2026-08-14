#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════
#  حاجزُ أوامر git الخطِرة — PreToolUse على أداة Bash.
#
#  **مقتبسٌ من مهارة `git-guardrails-claude-code` ومُعدَّلٌ عنها عمداً.**
#  الأصلُ يحجب `git push` كلَّه بلا استثناء، وهذا المشروع **مأمورٌ بالدفع**
#  إلى فرعين بعينهما. فحجبُ الدفع كلِّه يمنع العملَ المطلوب لا الخطأ.
#
#  فصار الحدُّ: يُحجب الدفعُ إلى **فرعٍ غيرِ مأذون**، لا الدفعُ نفسُه.
#
#  ══════════════════════════════════════════════════════════════════════
#  **ولا يُطلَق هذا في بيئة التشغيل عن بُعد.** قِيس: `CLAUDE_PROJECT_DIR`
#  غيرُ مضبوطٍ أصلاً، وجذرُ المشروع `/home/user` لا `/home/user/Anjaz`،
#  فلا تُقرأ `Anjaz/.claude/settings.json` ولا يُطلَق خطّافٌ منها.
#
#  فهو يحمي **جلساتِ صاحب المشروع على جهازه** وحدَها. ومن ظنّه يحمي
#  البيئةَ البعيدة ترك الاحتياطَ حيث لا حارس — **ووعدُ أمانٍ لا وجودَ له
#  أسوأ من غيابه.**
#
#  ومنطقُه مُجرَّبٌ بالعكس في `tests/Feature/GitGuardrailHookTest.php`:
#  كلُّ سطرٍ يُحجب جُرِّب أنّه يُحجب، وكلُّ سطرٍ يُسمح جُرِّب أنّه يمرّ.
# ══════════════════════════════════════════════════════════════════════

set -uo pipefail

ALLOWED_BRANCHES=(
  "claude/project-development-continuation-7oxhip"
  "claude/project-code-review-yjagv"
)

CMD=$(cat | jq -r '.tool_input.command // empty' 2>/dev/null)

[[ -z "$CMD" ]] && exit 0

block() {
  echo "BLOCKED: $1" >&2
  echo "لا صلاحيةَ لهذا الأمر. الفروعُ المأذونة: ${ALLOWED_BRANCHES[*]}" >&2
  exit 2
}

# ── ① الأوامر المدمِّرة — لا استثناء لها ────────────────────────────
#     (تُقرأ من الأمر كلِّه: `cd x && git reset --hard` أمرٌ واحد.)
grep -qE '(^|[;&|]|\s)git\s+reset\s+.*--hard' <<<"$CMD" && block "git reset --hard"
grep -qE '(^|[;&|]|\s)git\s+clean\s+(-[a-zA-Z]*f)'  <<<"$CMD" && block "git clean -f"
grep -qE '(^|[;&|]|\s)git\s+branch\s+.*-D'          <<<"$CMD" && block "git branch -D"
grep -qE '(^|[;&|]|\s)git\s+checkout\s+\.\s*($|[;&|])' <<<"$CMD" && block "git checkout ."
grep -qE '(^|[;&|]|\s)git\s+restore\s+\.\s*($|[;&|])'  <<<"$CMD" && block "git restore ."

# ── ② الدفع ────────────────────────────────────────────────────────
if grep -qE '(^|[;&|]|\s)git\s+push' <<<"$CMD"; then

  # `--force` العارية تُعيد كتابة تاريخ الآخرين بلا فحص.
  # و`--force-with-lease` مأذونةٌ صراحةً (إعادةُ تأسيس فرعٍ دُمج طلبُه).
  if grep -qP '(--force(?!-with-lease)|\s-f(\s|$))' <<<"$CMD"; then
    block "git push --force"
  fi

  # لا بدّ من تسمية فرعٍ مأذونٍ في الأمر. ودفعٌ بلا وجهةٍ صريحة
  # يذهب إلى الفرع الحاليّ — وهو مجهولٌ هنا، فيُحجب.
  ok=0
  for b in "${ALLOWED_BRANCHES[@]}"; do
    grep -qF -- "$b" <<<"$CMD" && ok=1 && break
  done

  [[ $ok -eq 0 ]] && block "git push إلى فرعٍ غيرِ مأذون (أو بلا وجهةٍ صريحة)"
fi

exit 0
