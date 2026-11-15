# ملحق — جرد الجداول من ملفات الهجرة

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

أسماء مرجعية من `Schema::create/table` في 331 ملف هجرة؛ **لا تثبت** وجود الجدول في قاعدة الإنتاج ولا مخططه النهائي. «المجال» تصنيف آلي بالاسم يحتاج مراجعة بشرية قبل أي قرار ترحيل.

| الجدول | المجال (آلي) | أول هجرة | عدد الهجرات |
|---|---|---|---:|
| `account_recovery_requests` | PAY — عميل/عام | `2026_05_15_110004_amial_create_account_recovery_requests_table.php` | 2 |
| `account_security_events` | PAY — عميل/عام | `2026_05_15_100006_amial_create_account_security_events_table.php` | 1 |
| `addon_settings` | PAY — عميل/عام | `2026_07_26_000006_amial_create_addon_settings_table.php` | 1 |
| `admin_user_permissions` | ADM/IAM (هدف) | `2026_08_28_130000_amial_operator_tab_access.php` | 1 |
| `admin_user_roles` | ADM/IAM (هدف) | `2026_07_27_120000_amial_platform_operator_roles.php` | 1 |
| `agent_announcements` | AGT (هدف) — يعيش في Pay | `2026_08_03_100000_amial_teller_workspace.php` | 1 |
| `agent_branches` | AGT (هدف) — يعيش في Pay | `2026_07_31_200000_amial_agent_branches_and_till.php` | 1 |
| `agent_cash_movements` | AGT (هدف) — يعيش في Pay | `2026_07_31_200000_amial_agent_branches_and_till.php` | 3 |
| `agent_cash_tills` | AGT (هدف) — يعيش في Pay | `2026_07_31_200000_amial_agent_branches_and_till.php` | 1 |
| `agent_daily_settlements` | AGT (هدف) — يعيش في Pay | `2026_08_02_120000_amial_agent_daily_settlements.php` | 1 |
| `agent_float_logs` | AGT (هدف) — يعيش في Pay | `2026_05_22_110001_amial_create_agent_network.php` | 1 |
| `agent_panic_alerts` | AGT (هدف) — يعيش في Pay | `2026_08_03_100000_amial_teller_workspace.php` | 1 |
| `agent_profiles` | AGT (هدف) — يعيش في Pay | `2026_05_22_110001_amial_create_agent_network.php` | 1 |
| `agent_settlements` | AGT (هدف) — يعيش في Pay | `2026_05_22_110001_amial_create_agent_network.php` | 1 |
| `agent_shifts` | AGT (هدف) — يعيش في Pay | `2026_08_01_100000_amial_agent_staff_and_shifts.php` | 2 |
| `agent_staff` | AGT (هدف) — يعيش في Pay | `2026_08_01_100000_amial_agent_staff_and_shifts.php` | 3 |
| `agent_teller_requests` | AGT (هدف) — يعيش في Pay | `2026_08_03_100000_amial_teller_workspace.php` | 1 |
| `agent_work_events` | AGT (هدف) — يعيش في Pay | `2026_08_03_200000_amial_work_time.php` | 1 |
| `amial_notifications` | PAY — عميل/عام | `2026_05_24_150001_amial_create_notifications.php` | 1 |
| `aml_alerts` | PAY — امتثال | `2026_05_18_120002_amial_create_aml_flagged_profiles_alerts.php` | 1 |
| `aml_flagged_transactions` | PAY — مال ودفتر | `2026_05_18_120002_amial_create_aml_flagged_profiles_alerts.php` | 1 |
| `aml_investigation_documents` | PAY — امتثال | `2026_07_31_150000_amial_aml_investigations.php` | 1 |
| `aml_investigation_events` | PAY — امتثال | `2026_07_31_150000_amial_aml_investigations.php` | 1 |
| `aml_investigations` | PAY — امتثال | `2026_07_31_150000_amial_aml_investigations.php` | 1 |
| `aml_regulatory_reports` | PAY — امتثال | `2026_07_31_160000_amial_aml_regulatory_reports.php` | 1 |
| `aml_rule_evaluations` | PAY — امتثال | `2026_05_18_120001_amial_create_aml_rules_evaluations.php` | 2 |
| `aml_rules` | PAY — امتثال | `2026_05_18_120001_amial_create_aml_rules_evaluations.php` | 2 |
| `aml_shadow_decisions` | PAY — امتثال | `2026_05_22_120001_amial_aml_shadow_mode.php` | 1 |
| `aml_user_risk_profiles` | PAY — امتثال | `2026_05_18_120002_amial_create_aml_flagged_profiles_alerts.php` | 1 |
| `approval_requests` | ADM/IAM (هدف) | `2026_07_05_120000_create_insider_defense_tables.php` | 1 |
| `audit_chain_head` | ADM/IAM (هدف) | `2026_07_05_120000_create_insider_defense_tables.php` | 1 |
| `audit_decisions` | ADM/IAM (هدف) | `2026_05_15_100005_amial_create_audit_decisions_table.php` | 3 |
| `banners` | PAY — عميل/عام | `2021_12_21_110529_create_banners_table.php` | 2 |
| `bill_payment_orders` | PAY — مال ودفتر | `2026_05_16_120006_amial_create_bill_pay_orders_table.php` | 4 |
| `bill_provider_requests` | PAY — مال ودفتر | `2026_05_16_120006_amial_create_bill_pay_orders_table.php` | 2 |
| `bill_provider_webhook_events` | PAY — مال ودفتر | `2026_09_17_110000_amial_free_sadad_integration_hub.php` | 2 |
| `bill_providers` | PAY — مال ودفتر | `2026_05_16_120005_amial_create_bill_pay_structure_table.php` | 2 |
| `bill_service_products` | BUS (هدف) — قديم داخل Pay | `2026_05_16_120005_amial_create_bill_pay_structure_table.php` | 1 |
| `bill_services` | PAY — مال ودفتر | `2026_05_16_120005_amial_create_bill_pay_structure_table.php` | 1 |
| `blog_categories` | PAY — عميل/عام | `2024_11_27_170730_create_blog_categories_table.php` | 2 |
| `blogs` | PAY — عميل/عام | `2024_11_25_100656_create_blogs_table.php` | 4 |
| `bonuses` | PAY — عميل/عام | `2023_03_25_082756_create_bonuses_table.php` | 1 |
| `branches` | BUS (هدف) — قديم داخل Pay | `2026_06_05_100001_amial_create_branches.php` | 1 |
| `business_audit_events` | ADM/IAM (هدف) | `2026_11_07_100000_create_business_audit_events.php` | 1 |
| `business_customer_debts` | PAY — قناة أعمال | `2026_11_01_090000_add_business_credit_handover_and_debt_mirror.php` | 1 |
| `business_links` | PAY — قناة أعمال | `2026_10_31_090000_create_business_gateway_tables.php` | 3 |
| `business_outbox_events` | PAY — قناة أعمال | `2026_10_31_090000_create_business_gateway_tables.php` | 1 |
| `business_payment_intents` | PAY — قناة أعمال | `2026_10_31_090000_create_business_gateway_tables.php` | 3 |
| `business_refunds` | PAY — قناة أعمال | `2026_11_05_090000_create_business_refunds.php` | 1 |
| `business_request_nonces` | PAY — قناة أعمال | `2026_10_31_090000_create_business_gateway_tables.php` | 1 |
| `business_settings` | PAY — قناة أعمال | `2021_11_18_104105_create_business_settings_table.php` | 1 |
| `cache` | PAY — عميل/عام | `0001_01_01_000001_create_cache_table.php` | 1 |
| `cache_locks` | PAY — عميل/عام | `0001_01_01_000001_create_cache_table.php` | 1 |
| `cash_handovers` | PAY — عميل/عام | `2026_08_21_180000_amial_cash_handovers.php` | 1 |
| `cashier_shifts` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000008_amial_create_cashier_shifts.php` | 3 |
| `charity_campaigns` | PAY — مال ودفتر | `2026_05_17_180002_amial_create_charity_campaigns_donations_settlements.php` | 3 |
| `charity_categories` | PAY — مال ودفتر | `2026_05_17_180001_amial_create_charity_organizations_table.php` | 1 |
| `charity_organizations` | PAY — مال ودفتر | `2026_05_17_180001_amial_create_charity_organizations_table.php` | 2 |
| `charity_settlements` | PAY — مال ودفتر | `2026_05_17_180002_amial_create_charity_campaigns_donations_settlements.php` | 2 |
| `contact_messages` | PAY — عميل/عام | `2023_09_18_054929_create_contact_messages_table.php` | 1 |
| `corporate_account_members` | BUS (هدف) — قديم داخل Pay | `2026_07_19_000003_amial_create_corporate_accounts.php` | 1 |
| `corporate_account_movements` | BUS (هدف) — قديم داخل Pay | `2026_07_19_000003_amial_create_corporate_accounts.php` | 1 |
| `corporate_accounts` | BUS (هدف) — قديم داخل Pay | `2026_07_19_000003_amial_create_corporate_accounts.php` | 1 |
| `currencies` | PAY — عميل/عام | `2021_11_20_090531_create_currencies_table.php` | 1 |
| `customer_credit_accounts` | BUS (هدف) — قديم داخل Pay | `2026_05_24_140001_amial_create_customer_credit.php` | 1 |
| `customer_credit_collections` | BUS (هدف) — قديم داخل Pay | `2026_09_26_190000_create_customer_credit_collections.php` | 1 |
| `customer_credit_movements` | BUS (هدف) — قديم داخل Pay | `2026_05_24_140001_amial_create_customer_credit.php` | 2 |
| `customer_death_cases` | PAY — عميل/عام | `2026_08_21_091000_amial_customer_death_cases.php` | 1 |
| `customer_notes` | PAY — عميل/عام | `2026_07_31_190000_amial_customer_center.php` | 1 |
| `customer_turnover_usage` | PAY — عميل/عام | `2026_09_15_151000_amial_customer_turnover_usage.php` | 1 |
| `dispute_reasons` | SUP (هدف) | `2025_06_03_145000_create_dispute_reasons_table.php` | 1 |
| `disputes` | SUP (هدف) | `2025_05_31_151928_create_disputes_table.php` | 1 |
| `donations` | PAY — مال ودفتر | `2026_05_17_180002_amial_create_charity_campaigns_donations_settlements.php` | 2 |
| `e_money` | PAY — مال ودفتر | `2021_12_01_063108_create_e_money_table.php` | 4 |
| `email_identity_conflicts` | MWT/هوية | `2026_09_12_150000_amial_canonical_email_identity.php` | 1 |
| `failed_jobs` | PAY — عميل/عام | `2019_08_19_000000_create_failed_jobs_table.php` | 1 |
| `family_fund_members` | PAY — مال ودفتر | `2026_05_16_120003_amial_create_family_fund_members_table.php` | 1 |
| `family_fund_transactions` | PAY — مال ودفتر | `2026_05_16_120004_amial_create_family_fund_transactions_table.php` | 1 |
| `family_funds` | PAY — مال ودفتر | `2026_05_16_120002_amial_create_family_funds_table.php` | 2 |
| `faq_categories` | PAY — عميل/عام | `2024_11_30_155612_create_faq_categories_table.php` | 1 |
| `faqs` | PAY — عميل/عام | `2024_11_25_100547_create_faqs_table.php` | 1 |
| `favourite_numbers` | PAY — عميل/عام | `2025_05_31_161629_create_favourite_numbers_table.php` | 2 |
| `feature_flags` | ADM/IAM (هدف) | `2026_07_05_130000_create_feature_flags_table.php` | 1 |
| `fee_change_logs` | PAY — مال ودفتر | `2026_05_23_120001_amial_create_fee_engine.php` | 1 |
| `fee_schemes` | PAY — مال ودفتر | `2026_05_23_120001_amial_create_fee_engine.php` | 2 |
| `fuel_company_accounts` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 1 |
| `fuel_company_cards` | BUS (هدف) — قديم داخل Pay | `2026_05_31_120001_amial_fuel_shifts_cards_variance.php` | 1 |
| `fuel_deliveries` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_meter_readings` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 1 |
| `fuel_nozzles` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_price_history` | BUS (هدف) — قديم داخل Pay | `2026_07_18_000001_amial_create_fuel_price_history.php` | 1 |
| `fuel_price_versions` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_products` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 1 |
| `fuel_pump_products` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 2 |
| `fuel_pumps` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 1 |
| `fuel_sales` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 4 |
| `fuel_shift_cash_movements` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_shift_pump_summaries` | BUS (هدف) — قديم داخل Pay | `2026_05_31_120001_amial_fuel_shifts_cards_variance.php` | 1 |
| `fuel_shifts` | BUS (هدف) — قديم داخل Pay | `2026_05_31_120001_amial_fuel_shifts_cards_variance.php` | 1 |
| `fuel_stations` | BUS (هدف) — قديم داخل Pay | `2026_05_31_100001_amial_create_fuel_station_tables.php` | 1 |
| `fuel_stock_reconciliations` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_suppliers` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_tank_dips` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_tanks` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `fuel_variance_records` | BUS (هدف) — قديم داخل Pay | `2026_05_31_120001_amial_fuel_shifts_cards_variance.php` | 1 |
| `funds` | PAY — مال ودفتر | `2021_12_05_051247_create_funds_table.php` | 1 |
| `fx_rates` | PAY — مال ودفتر | `2026_09_03_100000_amial_multi_currency_wallets.php` | 1 |
| `gift_card_transactions` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000007_amial_create_gift_cards.php` | 1 |
| `gift_cards` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000007_amial_create_gift_cards.php` | 1 |
| `held_sales` | BUS (هدف) — قديم داخل Pay | `2026_09_05_180000_amial_held_sales_open_tickets.php` | 1 |
| `help_topics` | PAY — عميل/عام | `2021_12_26_061202_create_help_topics_table.php` | 1 |
| `idempotency_keys` | PAY — مال ودفتر | `2026_05_15_100001_amial_create_idempotency_keys_table.php` | 1 |
| `installment_contracts` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000004_amial_create_installments.php` | 1 |
| `installment_plans` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000004_amial_create_installments.php` | 1 |
| `installment_schedules` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000004_amial_create_installments.php` | 1 |
| `job_batches` | PAY — عميل/عام | `0001_01_01_000003_create_jobs_table.php` | 1 |
| `jobs` | PAY — عميل/عام | `0001_01_01_000003_create_jobs_table.php` | 1 |
| `kyc_ai_reviews` | MWT/هوية | `2026_09_24_061000_create_kyc_ai_reviews_table.php` | 1 |
| `kyc_biometric_attempts` | MWT/هوية | `2026_09_14_191500_amial_kyc_biometric_provider_runtime.php` | 1 |
| `kyc_biometric_events` | MWT/هوية | `2026_09_14_191500_amial_kyc_biometric_provider_runtime.php` | 1 |
| `kyc_documents` | MWT/هوية | `2026_07_31_140000_amial_kyc_documents.php` | 3 |
| `kyc_forensic_views` | MWT/هوية | `2026_09_14_181500_amial_kyc_forensic_watermarking.php` | 1 |
| `kyc_tier_limits` | MWT/هوية | `2026_05_19_130001_amial_sanction_and_kyc_tiers.php` | 2 |
| `kyc_verification_cases` | MWT/هوية | `2026_09_14_183000_amial_kyc_privacy_cases.php` | 2 |
| `ledger_accounts` | PAY — مال ودفتر | `2026_05_19_100001_amial_create_double_entry_ledger.php` | 1 |
| `ledger_entry_lines` | PAY — مال ودفتر | `2026_05_19_100001_amial_create_double_entry_ledger.php` | 1 |
| `ledger_journal_entries` | PAY — مال ودفتر | `2026_05_19_100001_amial_create_double_entry_ledger.php` | 2 |
| `legal_name_events` | PAY — عميل/عام | `2026_09_19_183500_customer_legal_name_lifecycle.php` | 1 |
| `legal_terms` | PAY — عميل/عام | `2026_05_15_110002_amial_create_legal_terms_table.php` | 1 |
| `linked_websites` | PAY — عميل/عام | `2021_11_23_090107_create_linked_websites_table.php` | 1 |
| `loyalty_accounts` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000001_amial_create_loyalty.php` | 1 |
| `loyalty_movements` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000001_amial_create_loyalty.php` | 1 |
| `loyalty_programs` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000001_amial_create_loyalty.php` | 1 |
| `merchant_admin_actions` | BUS (هدف) — قديم داخل Pay | `2026_08_10_150000_amial_merchant_center_audit_and_access.php` | 1 |
| `merchant_api_keys` | BUS (هدف) — قديم داخل Pay | `2026_07_19_000005_amial_create_merchant_api_keys.php` | 1 |
| `merchant_asset_adjustments` | BUS (هدف) — قديم داخل Pay | `2026_10_01_208000_amial_accounting_reversals_and_return_settlements.php` | 1 |
| `merchant_asset_depreciations` | BUS (هدف) — قديم داخل Pay | `2026_10_01_204000_amial_fixed_assets_and_procurement_documents.php` | 1 |
| `merchant_attribute_terms` | BUS (هدف) — قديم داخل Pay | `2026_09_03_170000_amial_product_attributes.php` | 1 |
| `merchant_attributes` | BUS (هدف) — قديم داخل Pay | `2026_09_03_170000_amial_product_attributes.php` | 1 |
| `merchant_brands` | BUS (هدف) — قديم داخل Pay | `2026_08_10_110000_amial_retail_product_engine.php` | 1 |
| `merchant_bulk_import_rows` | BUS (هدف) — قديم داخل Pay | `2026_10_02_100000_add_merchant_bulk_import_rows.php` | 1 |
| `merchant_capability_overrides` | BUS (هدف) — قديم داخل Pay | `2026_08_10_140000_amial_entitlements_plan_capabilities.php` | 1 |
| `merchant_categories` | BUS (هدف) — قديم داخل Pay | `2026_08_10_110000_amial_retail_product_engine.php` | 1 |
| `merchant_currencies` | BUS (هدف) — قديم داخل Pay | `2026_07_19_000004_amial_create_merchant_currencies.php` | 2 |
| `merchant_data_access_grants` | BUS (هدف) — قديم داخل Pay | `2026_08_10_150000_amial_merchant_center_audit_and_access.php` | 1 |
| `merchant_expense_reversals` | BUS (هدف) — قديم داخل Pay | `2026_10_01_208000_amial_accounting_reversals_and_return_settlements.php` | 1 |
| `merchant_expenses` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000009_amial_create_expenses.php` | 2 |
| `merchant_fixed_assets` | BUS (هدف) — قديم داخل Pay | `2026_10_01_204000_amial_fixed_assets_and_procurement_documents.php` | 2 |
| `merchant_invoice_sequences` | BUS (هدف) — قديم داخل Pay | `2026_09_08_120000_amial_merchant_invoice_numbers.php` | 1 |
| `merchant_locations` | BUS (هدف) — قديم داخل Pay | `2026_08_09_150000_amial_retail_stock_by_location.php` | 1 |
| `merchant_payout_requests` | BUS (هدف) — قديم داخل Pay | `2026_09_28_120000_amial_create_merchant_payout_requests.php` | 1 |
| `merchant_permission_overrides` | BUS (هدف) — قديم داخل Pay | `2026_08_22_160000_merchant_permission_overrides.php` | 2 |
| `merchant_pos_device_activations` | BUS (هدف) — قديم داخل Pay | `2026_08_27_150000_amial_add_pos_device_activation_codes.php` | 1 |
| `merchant_pos_devices` | BUS (هدف) — قديم داخل Pay | `2026_08_16_190000_amial_create_merchant_pos_devices.php` | 1 |
| `merchant_print_jobs` | BUS (هدف) — قديم داخل Pay | `2026_10_02_090000_add_merchant_print_tracking.php` | 1 |
| `merchant_printer_profiles` | BUS (هدف) — قديم داخل Pay | `2026_10_02_090000_add_merchant_print_tracking.php` | 1 |
| `merchant_products` | BUS (هدف) — قديم داخل Pay | `2026_05_23_170001_amial_create_cashier.php` | 3 |
| `merchant_profiles` | BUS (هدف) — قديم داخل Pay | `2026_05_23_100001_amial_create_merchant_risk.php` | 5 |
| `merchant_refunds` | BUS (هدف) — قديم داخل Pay | `2026_05_19_100002_amial_create_merchant_refunds.php` | 2 |
| `merchant_risk_events` | BUS (هدف) — قديم داخل Pay | `2026_05_23_100001_amial_create_merchant_risk.php` | 1 |
| `merchant_risk_profiles` | BUS (هدف) — قديم داخل Pay | `2026_05_23_100001_amial_create_merchant_risk.php` | 1 |
| `merchant_role_permissions` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `merchant_roles` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 1 |
| `merchant_sale_items` | BUS (هدف) — قديم داخل Pay | `2026_08_10_100000_amial_retail_sale_lines.php` | 1 |
| `merchant_sales` | BUS (هدف) — قديم داخل Pay | `2026_05_23_170001_amial_create_cashier.php` | 9 |
| `merchant_shift_cash_movements` | BUS (هدف) — قديم داخل Pay | `2026_08_10_130000_amial_retail_pricing_reservations_roles.php` | 1 |
| `merchant_units` | BUS (هدف) — قديم داخل Pay | `2026_08_10_110000_amial_retail_product_engine.php` | 1 |
| `merchant_usage_counters` | BUS (هدف) — قديم داخل Pay | `2026_06_04_100001_amial_create_usage_counters.php` | 1 |
| `merchant_user_roles` | BUS (هدف) — قديم داخل Pay | `2026_08_09_130000_amial_fuel_vertical_phase_1_to_7.php` | 2 |
| `merchant_verification_requests` | BUS (هدف) — قديم داخل Pay | `2026_05_25_110001_amial_merchant_verification_requests.php` | 1 |
| `merchant_verticals` | BUS (هدف) — قديم داخل Pay | `2026_09_04_100000_amial_admin_defined_verticals.php` | 1 |
| `merchants` | BUS (هدف) — قديم داخل Pay | `2022_12_08_045549_create_merchants_table.php` | 2 |
| `muwathaq_account_opening_applications` | MWT/هوية | `2026_10_19_120000_create_muwathaq_account_opening_applications_table.php` | 4 |
| `muwathaq_application_submissions` | MWT/هوية | `2026_10_19_130000_create_muwathaq_application_submissions_table.php` | 1 |
| `muwathaq_opening_evidences` | MWT/هوية | `2026_10_21_090000_add_muwathaq_opening_progress_tables.php` | 1 |
| `muwathaq_review_requests` | MWT/هوية | `2026_11_12_100000_create_muwathaq_review_requests_table.php` | 1 |
| `muwathaq_reviewer_audit_events` | MWT/هوية | `2026_10_21_150000_create_muwathaq_reviewer_audit_events_table.php` | 1 |
| `news_letters` | PAY — عميل/عام | `2023_09_18_042428_create_news_letters_table.php` | 1 |
| `notification_delivery_logs` | PAY — عميل/عام | `2026_09_19_204500_create_notification_delivery_logs_table.php` | 1 |
| `notifications` | PAY — عميل/عام | `2021_11_23_123056_create_notifications_table.php` | 3 |
| `oauth_access_tokens` | ADM/IAM (هدف) | `2016_06_01_000002_create_oauth_access_tokens_table.php` | 1 |
| `oauth_auth_codes` | ADM/IAM (هدف) | `2016_06_01_000001_create_oauth_auth_codes_table.php` | 1 |
| `oauth_clients` | ADM/IAM (هدف) | `2016_06_01_000004_create_oauth_clients_table.php` | 1 |
| `oauth_personal_access_clients` | ADM/IAM (هدف) | `2016_06_01_000005_create_oauth_personal_access_clients_table.php` | 1 |
| `oauth_refresh_tokens` | ADM/IAM (هدف) | `2016_06_01_000003_create_oauth_refresh_tokens_table.php` | 1 |
| `operational_governorate_policies` | PAY — عميل/عام | `2026_09_19_171000_create_operational_governorate_policies.php` | 1 |
| `otp_challenges` | PAY — عميل/عام | `2026_09_12_100000_create_otp_challenges_table.php` | 1 |
| `otp_demo_numbers` | PAY — عميل/عام | `2026_08_07_100000_create_otp_demo_numbers_table.php` | 1 |
| `otp_issuance_locks` | PAY — عميل/عام | `2026_09_12_180000_create_otp_issuance_locks_table.php` | 1 |
| `password_resets` | PAY — عميل/عام | `2014_10_12_100000_create_password_resets_table.php` | 2 |
| `payment_records` | PAY — مال ودفتر | `2022_12_11_050638_create_payment_records_table.php` | 2 |
| `payment_requests` | PAY — مال ودفتر | `2026_05_25_100001_amial_create_payment_requests.php` | 1 |
| `pending_transfers` | PAY — مال ودفتر | `2026_05_22_140001_amial_create_pending_transfers.php` | 1 |
| `permissions` | ADM/IAM (هدف) | `2026_06_05_100003_amial_create_rbac.php` | 1 |
| `personal_access_tokens` | PAY — عميل/عام | `2019_12_14_000001_create_personal_access_tokens_table.php` | 1 |
| `pharmacies` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 1 |
| `pharmacy_batches` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 4 |
| `pharmacy_categories` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 1 |
| `pharmacy_customers` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 1 |
| `pharmacy_products` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 2 |
| `pharmacy_sale_items` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 1 |
| `pharmacy_sales` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 4 |
| `pharmacy_stock_alerts` | BUS (هدف) — قديم داخل Pay | `2026_06_01_100001_amial_create_pharmacy_tables.php` | 1 |
| `phone_verifications` | PAY — عميل/عام | `2021_11_27_041913_create_phone_verifications_table.php` | 2 |
| `pii_access_logs` | PAY — عميل/عام | `2026_05_18_100002_amial_create_pii_access_logs_table.php` | 1 |
| `plan_capabilities` | PAY — عميل/عام | `2026_08_10_140000_amial_entitlements_plan_capabilities.php` | 1 |
| `platform_fee_entries` | ADM/IAM (هدف) | `2026_05_24_130001_amial_create_platform_fee_entries.php` | 1 |
| `platform_login_pins` | ADM/IAM (هدف) | `2026_08_22_170000_amial_create_platform_login_pins.php` | 1 |
| `platform_operator_tab_access` | ADM/IAM (هدف) | `2026_08_28_130000_amial_operator_tab_access.php` | 1 |
| `pos_device_sessions` | BUS (هدف) — قديم داخل Pay | `2026_08_17_090000_amial_create_pos_device_sessions.php` | 1 |
| `pos_user_roles` | BUS (هدف) — قديم داخل Pay | `2026_06_05_100003_amial_create_rbac.php` | 1 |
| `pos_users` | BUS (هدف) — قديم داخل Pay | `2026_05_18_140001_amial_unified_login_columns.php` | 1 |
| `product_barcodes` | BUS (هدف) — قديم داخل Pay | `2026_08_10_110000_amial_retail_product_engine.php` | 1 |
| `product_catalog_entries` | BUS (هدف) — قديم داخل Pay | `2026_08_07_140000_amial_create_product_catalog.php` | 1 |
| `product_catalog_suggestions` | BUS (هدف) — قديم داخل Pay | `2026_08_07_140000_amial_create_product_catalog.php` | 1 |
| `product_price_versions` | BUS (هدف) — قديم داخل Pay | `2026_08_10_130000_amial_retail_pricing_reservations_roles.php` | 1 |
| `product_stocks` | BUS (هدف) — قديم داخل Pay | `2026_08_09_150000_amial_retail_stock_by_location.php` | 1 |
| `profile_change_requests` | PAY — عميل/عام | `2026_08_22_200000_profile_change_requests.php` | 1 |
| `promotions` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000002_amial_create_promotions.php` | 1 |
| `purchase_order_items` | BUS (هدف) — قديم داخل Pay | `2026_07_13_000001_amial_create_suppliers_and_purchase_orders.php` | 3 |
| `purchase_orders` | BUS (هدف) — قديم داخل Pay | `2026_07_13_000001_amial_create_suppliers_and_purchase_orders.php` | 2 |
| `purchase_return_items` | BUS (هدف) — قديم داخل Pay | `2026_09_05_100000_amial_purchase_returns_and_cash_purchase.php` | 1 |
| `purchase_returns` | BUS (هدف) — قديم داخل Pay | `2026_09_05_100000_amial_purchase_returns_and_cash_purchase.php` | 3 |
| `purposes` | PAY — عميل/عام | `2021_12_06_101224_create_purposes_table.php` | 1 |
| `rbac_audit_log` | ADM/IAM (هدف) | `2026_05_17_100001_amial_create_rbac_tables.php` | 1 |
| `rbac_permissions` | ADM/IAM (هدف) | `2026_05_17_100001_amial_create_rbac_tables.php` | 1 |
| `rbac_role_permissions` | ADM/IAM (هدف) | `2026_05_17_100001_amial_create_rbac_tables.php` | 1 |
| `rbac_roles` | ADM/IAM (هدف) | `2026_05_17_100001_amial_create_rbac_tables.php` | 1 |
| `rbac_user_roles` | ADM/IAM (هدف) | `2026_05_17_100001_amial_create_rbac_tables.php` | 1 |
| `receipts` | PAY — مال ودفتر | `2026_05_16_120001_amial_create_receipts_table.php` | 2 |
| `reconciliation_cases` | PAY — مال ودفتر | `2026_08_20_120000_amial_reconciliation_cases.php` | 2 |
| `reconciliation_runs` | PAY — مال ودفتر | `2026_08_07_090000_create_reconciliation_runs_table.php` | 1 |
| `registration_dossiers` | MWT/هوية | `2026_08_28_120000_amial_registration_dossiers.php` | 1 |
| `report_exports` | PAY — عميل/عام | `2026_05_23_110001_amial_create_report_exports.php` | 1 |
| `request_money` | PAY — عميل/عام | `2021_12_04_113130_create_request_money_table.php` | 1 |
| `residence_verifications` | PAY — عميل/عام | `2026_09_15_041500_amial_progressive_kyc_residence.php` | 2 |
| `restaurant_orders` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000005_amial_create_restaurant.php` | 3 |
| `restaurant_tables` | BUS (هدف) — قديم داخل Pay | `2026_07_20_000005_amial_create_restaurant.php` | 1 |
| `role_permissions` | ADM/IAM (هدف) | `2026_06_05_100003_amial_create_rbac.php` | 1 |
| `roles` | ADM/IAM (هدف) | `2026_06_05_100003_amial_create_rbac.php` | 1 |
| `safe_payment_events` | PAY — مال ودفتر | `2026_05_17_140002_amial_create_safe_payment_events_table.php` | 1 |
| `safe_payment_evidence` | PAY — مال ودفتر | `2026_07_26_000003_amial_safe_payment_evidence.php` | 1 |
| `safe_payments` | PAY — مال ودفتر | `2026_05_17_140001_amial_create_safe_payments_table.php` | 4 |
| `saher_baselines` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 1 |
| `saher_finding_events` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 1 |
| `saher_finding_evidence` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 1 |
| `saher_findings` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 2 |
| `saher_gate_receipts` | PAY — مال ودفتر | `2026_08_22_140000_saher_gate_receipts.php` | 1 |
| `saher_scan_runs` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 1 |
| `saher_sources` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 1 |
| `saher_suppressions` | PAY — عميل/عام | `2026_08_22_100000_saher_foundation.php` | 1 |
| `sale_return_items` | PAY — عميل/عام | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `sale_returns` | PAY — عميل/عام | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `sanction_list_entries` | PAY — امتثال | `2026_05_19_130001_amial_sanction_and_kyc_tiers.php` | 1 |
| `sanction_screening_logs` | PAY — امتثال | `2026_05_19_130001_amial_sanction_and_kyc_tiers.php` | 2 |
| `security_alerts` | PAY — عميل/عام | `2026_07_05_120000_create_insider_defense_tables.php` | 1 |
| `sentinel_blocked_ips` | PAY — امتثال | `2026_06_06_100002_amial_create_sentinel_blocked_ips.php` | 1 |
| `sentinel_events` | PAY — عميل/عام | `2026_06_06_100001_amial_create_sentinel_events.php` | 1 |
| `sessions` | ADM/IAM (هدف) | `0001_01_01_000002_create_sessions_table.php` | 1 |
| `settlement_audit_logs` | ADM/IAM (هدف) | `2026_06_07_100003_amial_create_settlement_audit_logs.php` | 1 |
| `settlement_partners` | PAY — مال ودفتر | `2026_06_07_100001_amial_create_settlement_partners.php` | 1 |
| `settlements` | PAY — مال ودفتر | `2026_06_07_100002_amial_create_settlements.php` | 2 |
| `social_media` | PAY — عميل/عام | `2023_09_20_095151_create_social_media_table.php` | 1 |
| `split_bill_participants` | PAY — مال ودفتر | `2026_05_23_160001_amial_create_split_bills.php` | 1 |
| `split_bills` | PAY — مال ودفتر | `2026_05_23_160001_amial_create_split_bills.php` | 1 |
| `stock_count_items` | BUS (هدف) — قديم داخل Pay | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `stock_counts` | BUS (هدف) — قديم داخل Pay | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `stock_movements` | BUS (هدف) — قديم داخل Pay | `2026_08_09_150000_amial_retail_stock_by_location.php` | 1 |
| `stock_reservations` | BUS (هدف) — قديم داخل Pay | `2026_08_10_130000_amial_retail_pricing_reservations_roles.php` | 1 |
| `stock_transfer_items` | BUS (هدف) — قديم داخل Pay | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `stock_transfers` | BUS (هدف) — قديم داخل Pay | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `stock_wastes` | BUS (هدف) — قديم داخل Pay | `2026_08_10_120000_amial_retail_operations.php` | 1 |
| `subscription_changes` | PAY — عميل/عام | `2026_06_04_200001_amial_create_subscription_changes.php` | 1 |
| `subscription_plans` | PAY — عميل/عام | `2026_11_09_100000_create_subscription_plans.php` | 1 |
| `supplier_ledger` | BUS (هدف) — قديم داخل Pay | `2026_07_13_000001_amial_create_suppliers_and_purchase_orders.php` | 4 |
| `suppliers` | BUS (هدف) — قديم داخل Pay | `2026_07_13_000001_amial_create_suppliers_and_purchase_orders.php` | 3 |
| `support_ticket_events` | SUP (هدف) | `2026_07_05_100000_create_support_tickets_tables.php` | 1 |
| `support_tickets` | SUP (هدف) | `2026_07_05_100000_create_support_tickets_tables.php` | 1 |
| `system_errors` | PAY — عميل/عام | `2026_08_15_030000_amial_system_health_and_errors.php` | 2 |
| `system_health_checks` | PAY — عميل/عام | `2026_08_15_030000_amial_system_health_and_errors.php` | 1 |
| `transaction_limits` | PAY — مال ودفتر | `2023_05_28_085211_create_transaction_limits_table.php` | 1 |
| `transactions` | PAY — مال ودفتر | `2021_12_01_053955_create_transactions_table.php` | 12 |
| `transfers` | PAY — مال ودفتر | `2021_11_29_100204_create_transfers_table.php` | 2 |
| `two_factor_attempts` | PAY — عميل/عام | `2026_05_19_120001_amial_add_two_factor_auth.php` | 1 |
| `unified_login_attempts` | ADM/IAM (هدف) | `2026_05_18_140001_amial_unified_login_columns.php` | 1 |
| `user_legal_acceptances` | PAY — عميل/عام | `2026_05_15_110003_amial_create_user_legal_acceptances_table.php` | 1 |
| `user_log_histories` | PAY — عميل/عام | `2022_10_18_141838_create_user_log_histories_table.php` | 2 |
| `users` | PAY — عميل/عام | `2014_10_12_000000_create_users_table.php` | 40 |
| `verification_case_events` | PAY — عميل/عام | `2026_10_08_111500_amial_unified_verification_workflow.php` | 1 |
| `verification_case_steps` | PAY — عميل/عام | `2026_10_08_111500_amial_unified_verification_workflow.php` | 1 |
| `verification_requirement_policies` | PAY — عميل/عام | `2026_10_08_111500_amial_unified_verification_workflow.php` | 1 |
| `whatsapp_audit_logs` | ADM/IAM (هدف) | `2026_06_18_100002_amial_create_whatsapp_audit_logs.php` | 1 |
| `whatsapp_linked_devices` | ADM/IAM (هدف) | `2026_06_18_100001_amial_create_whatsapp_linked_devices.php` | 3 |
| `wholesale_businesses` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 1 |
| `wholesale_collections` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 2 |
| `wholesale_customers` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 1 |
| `wholesale_invoice_item_lots` | BUS (هدف) — قديم داخل Pay | `2026_08_27_010000_amial_wholesale_units_and_lots.php` | 1 |
| `wholesale_invoice_items` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 2 |
| `wholesale_invoices` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 2 |
| `wholesale_price_tiers` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 1 |
| `wholesale_product_lots` | BUS (هدف) — قديم داخل Pay | `2026_08_27_010000_amial_wholesale_units_and_lots.php` | 1 |
| `wholesale_product_prices` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 1 |
| `wholesale_product_units` | BUS (هدف) — قديم داخل Pay | `2026_08_27_010000_amial_wholesale_units_and_lots.php` | 1 |
| `wholesale_products` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 1 |
| `wholesale_return_items` | BUS (هدف) — قديم داخل Pay | `2026_08_26_170000_add_wholesale_returns.php` | 2 |
| `wholesale_return_settlements` | BUS (هدف) — قديم داخل Pay | `2026_10_02_080000_add_wholesale_return_settlements.php` | 1 |
| `wholesale_returns` | BUS (هدف) — قديم داخل Pay | `2026_08_26_170000_add_wholesale_returns.php` | 1 |
| `wholesale_sales_reps` | BUS (هدف) — قديم داخل Pay | `2026_06_03_100001_amial_create_wholesale_tables.php` | 1 |
| `withdraw_requests` | PAY — مال ودفتر | `2022_10_18_040302_create_withdraw_requests_table.php` | 2 |
| `withdrawal_methods` | PAY — مال ودفتر | `2022_10_16_063545_create_withdrawal_methods_table.php` | 1 |
| `withdrawal_requests` | PAY — مال ودفتر | `2026_05_24_120002_amial_create_withdrawal_requests.php` | 2 |
| `women_tier3_sync_events` | PAY — عميل/عام | `2026_11_14_140100_create_women_tier3_sync_events_table.php` | 1 |
| `wrong_transfer_claims` | PAY — مال ودفتر | `2026_08_31_180000_amial_wrong_transfer_claims.php` | 1 |
| `zone_assignment_logs` | PAY — عميل/عام | `2026_05_22_100001_amial_fix_zone_default.php` | 2 |

## ملخص المجالات

| المجال | عدد الجداول |
|---|---:|
| BUS (هدف) — قديم داخل Pay | 124 |
| PAY — عميل/عام | 68 |
| PAY — مال ودفتر | 44 |
| ADM/IAM (هدف) | 28 |
| MWT/هوية | 14 |
| AGT (هدف) — يعيش في Pay | 13 |
| PAY — امتثال | 12 |
| PAY — قناة أعمال | 7 |
| SUP (هدف) | 4 |
