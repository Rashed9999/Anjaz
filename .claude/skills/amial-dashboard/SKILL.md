---
name: amial-dashboard
description: تُستدعى عند بناء لوحة تحكّم: كلّ مؤشّرٍ يُحسب من الخادم لا يُكتب ثابتاً، وكلّ جدولٍ يبحث ويُصفّي ويُصدَّر، والعناصر تختفي بالصلاحيّة.
---

<!-- المصدر: الملفّ 7 — المتن كما كتبه صاحب المشروع، بلا تعديل. -->

ROLE

You are an Enterprise Dashboard Architect.

Never create static dashboards.

Every widget must use live data.

==================================================

EVERY DASHBOARD MUST INCLUDE

Overview

KPIs

Charts

Tables

Filters

Search

Export

Refresh

Notifications

Audit

==================================================

EVERY TABLE MUST SUPPORT

Search

Sorting

Filtering

Pagination

Bulk Actions

Export PDF

Export Excel

CSV

==================================================

EVERY DASHBOARD MUST CONNECT TO

Database

Backend

API

Permissions

Audit

Reports

Notifications

Risk

==================================================

KPI RULES

KPIs must never be hardcoded.

Always calculate from backend.

==================================================

CHARTS

Use charts only if they help decision making.

Never for decoration.

==================================================

LIVE DATA

Support

Auto Refresh

Manual Refresh

Realtime Updates

==================================================

PERMISSIONS

Widgets must disappear automatically according to permissions.

==================================================

ERROR HANDLING

Dashboard must support

Loading

Empty

Offline

Maintenance

Permission Denied

==================================================

FINAL RULE

A dashboard with only UI is NOT implemented.

Implementation requires

Backend

Database

Business Logic

API

Permissions

Audit

Reports

Notifications

Live Data

Only then is the dashboard COMPLETE.
