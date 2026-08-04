# Release Checklist

Use this manual checklist only as a fallback after the automated stage already ran Playwright browser smoke successfully, or when the user explicitly asks for extra spot checks.

## Optional Spot Checks

- Open `/`
- Open `/demo/routing`
- Open `/demo/routing/basic`
- Open `/demo/rendering`
- Open `/demo/rendering/components`
- Open `/demo/rendering/seo`
- Open `/demo/events/sse`
- Open `/demo/platform/tenancy/resolution`
- Open `/demo/api/graphql`
- Open `/demo/cli/runtime-maintenance`

Expected result for each:
- page renders
- no `401`, `403`, or `500`
- no obvious broken layout
- top nav and left feature tree stay usable
- footer still shows `© 2026 Semitexa`

## Optional Deep Checks

- On `/`, confirm the `Get Started` section shows the canonical install command `curl -fsSL https://semitexa.com/install.sh | bash`
- On `/demo/rendering`, confirm the `Section Overview` shell and rendering manifesto block are visible
- On `/demo/rendering/components`, switch several code tabs and confirm each tab panel contains non-empty source
- On `/demo/rendering/seo`, confirm the page still exposes expected metadata
- On `/demo/events/sse`, confirm the stream page renders controls/state copy and no obvious reconnect-loop errors appear
- On `/demo/platform/tenancy/resolution`, confirm tenancy content renders inside the same feature shell without broken sidebar state

## Final Outcome

Reply back with one of:
- `manual qa done`
- `є проблеми`
- `не готово`

The default release workflow does not wait for `manual qa done`. Manual QA is optional fallback-only and does not trigger any automatic publish step.
