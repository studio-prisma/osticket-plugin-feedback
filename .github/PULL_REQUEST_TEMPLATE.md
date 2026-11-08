## What changes

<!-- One or two sentences. What does this PR do, and why? -->

## Type

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] CI / tooling
- [ ] Refactor (no behaviour change)

## Security impact

- [ ] Changes the token or its validation (`FeedbackToken`)
- [ ] Changes the public click page, CSRF or rate limits (`FeedbackPage`)
- [ ] Changes what is stored or how long (`FeedbackStore`)
- [ ] Changes who gets asked (`FeedbackRules`, `FeedbackTrigger`)
- [ ] No security-relevant surface touched

If any box above is checked, describe what an outsider with a link (or without one) can now do:

<!-- ... -->

## Verification

- [ ] `php tests/unit.php` passes (PHP version: ______)
- [ ] `npx playwright test` passes against `dev/up.sh`
- [ ] No hardcoded hostnames, credentials or absolute paths added

## Related issues

Closes #
