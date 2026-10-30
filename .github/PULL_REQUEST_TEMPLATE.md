## Problem and acceptance criteria
<!-- Issue, expected observable behavior, affected users, scope excluded. -->

## Base revision and scope
<!-- Base branch/SHA, files changed, difference from fork upstream if relevant. -->

## Diagnosis / design decision
<!-- What was OBSERVED, inferred from CODE, or remains a HYPOTHESIS? -->

## Risk and compatibility
<!-- ACL, multi-user isolation, security/CSRF, billing, DB/migrations, cron, VoIP, UI themes, generated assets. -->

## Verification evidence
<!-- Exact executed commands/steps, environment, expected/actual, evidence. Do NOT equate mocks and lint with end-to-end. -->

| Test ID | Environment and evidence | Status |
| --- | --- | --- |
| Positive path | | NOT RUN |
| Negative/permission | | NOT RUN |
| Regression | | NOT RUN |

## Deploy / rollback
<!-- Not deployed, staging, or explicitly authorized production. Include migration and rollback details or N/A. -->

## Checklist
- [ ] No secrets/customer data or unrelated changes.
- [ ] Backend permissions, cross-user exposure and business invariants reviewed where relevant.
- [ ] Tests executed with evidence; blocked/not-run cases labeled honestly.
- [ ] Authenticated UI and theme interactions tested if frontend changed.
- [ ] Code-derived docs, translations and generated assets updated where needed.
- [ ] Independent QA/AppSec consulted for high/critical-risk changes.
- [ ] No merge to main or deployment claimed without authorization.

See [engineering workflow](../docs/engineering/WORKFLOW.md) and [QA protocol](../docs/engineering/QA_EVIDENCE.md).
