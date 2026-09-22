# Feature matrix

| Capability | Public free baseline | Ashbi target | Phase |
|---|---:|---:|---:|
| Simple product subscriptions | Yes | Yes | 1 |
| Daily/weekly/monthly/yearly billing | Yes | Yes | 1 |
| Free trials | Yes | Yes | 1 |
| Admin subscription management | Yes | Yes | 1 |
| Customer subscription portal | Yes | Yes | 1 |
| Guest checkout | Yes | Yes | 1 |
| HPOS compatibility | Claimed | Yes; packaged disposable runtime passes the HPOS on/off matrix; client-clone matrix pending | 1 |
| Variable product subscriptions | Pro gap | Yes | 3 |
| Automated Stripe renewals | Pro/marketing ambiguity | Implemented; gateway sandbox verification pending | 2 |
| Sign-up fees | Pro gap | Yes | 3 |
| Split/installment payments | Pro gap | Yes | 3 |
| Pause/resume | Pro gap | Yes | 3 |
| Scheduled cancellation | Pro gap | Yes | 3 |
| Early/manual renewal | Pro gap | Yes | 3 |
| Custom renewal pricing | Pro gap | Yes | 3 |
| Customer payment-method change | Pro gap | Yes for saved Stripe tokens; PayPal local replacement is intentionally unsupported pending a tested provider-managed billing-agreement reauthorization flow | 3 |
| Smart retry and grace periods | Pro gap | Yes | 2 |
| Recurring coupons | Pro gap | Yes | 4 |
| Upgrade/downgrade | Pro gap | Yes | 4 |
| MRR/churn/revenue-at-risk | Pro gap | Yes | 4 |
| Recovery campaigns | Pro gap | Yes | 4 |
| Cancellation analytics | Partial/ambiguous | Yes | 4 |
| Privacy-safe aggregate report export and retention controls | Pro gap | Yes | 4 |
| Role-based visibility | Pro gap | Optional | 5 |
| Delivery schedules | Pro gap | Optional | 5 |
| REST API and activity log | Pro gap | Implemented; local disposable runtime passes, clone verification pending | 2 |
| Authenticated aggregate support diagnostics | Pro gap | Implemented; local disposable runtime passes, clone verification pending | 2 |
| LMS/CRM/automation integrations | Pro gap | Adapter SDK | 5 |
| Multilingual and Blocks support | Pro gap/ambiguous | Implemented; packaged disposable HPOS/checkout matrix passes; client-site matrix pending | 5 |

“Implemented” means the behavior exists in the maintained source and is covered
by the fast test suite. The local disposable WordPress/WooCommerce check now
passes the covered activation, diagnostics, lifecycle, paid switching,
recurring-coupon classification and renewal carry-through, customer-owned saved Stripe-token replacement, reporting, uninstall,
and admin-rendering
paths. Runtime verification remains a separate release
gate for the HPOS/checkout matrix, gateway sandboxes, and a clean client clone.
Where vendor marketing is inconsistent, treat the feature as unverified until
the public source and a clean staging installation demonstrate it.
