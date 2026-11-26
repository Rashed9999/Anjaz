import React from "react"
import { readFileSync } from "node:fs"
import { renderToStaticMarkup } from "react-dom/server"
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from "./card"

const map = JSON.parse(readFileSync("architecture.json", "utf8"))
const build = JSON.parse(readFileSync(".cache/build.json", "utf8"))
if (!build.ok) throw new Error("Floorplan build failed")
const page = <html lang="ar" dir="rtl"><head><meta charSet="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>خريطة أميال باي</title><link rel="stylesheet" href="overview.css"/></head>
  <body className="bg-slate-50 text-slate-900"><main className="mx-auto max-w-4xl p-6">
    <h1 className="mb-4 text-3xl font-semibold">خريطة أميال باي</h1>
    <a className="text-blue-800 underline" href="index.html">استكشف الملفات والروابط على الخريطة التفاعلية</a>
    <p className="my-4">رصدت الخريطة {build.links.total.toLocaleString("ar")} رابطًا بين أجزاء المشروع.</p>
    <div className="grid gap-4 sm:grid-cols-2">{map.nodes.map(node =>
      <Card key={node.id}><CardHeader><CardTitle>{node.label}</CardTitle>
        <CardDescription>{node.summary}</CardDescription></CardHeader>
        <CardContent><code dir="ltr" className="block break-all text-left text-sm">{node.paths[0]}</code></CardContent>
      </Card>)}</div></main></body></html>
process.stdout.write("<!doctype html>" + renderToStaticMarkup(page))
