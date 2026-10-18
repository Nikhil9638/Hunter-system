# Dependency advisory assessment — partial, 2026-10-15

`npm.cmd audit --cache .npm-audit-cache --json` failed at the registry audit request.
There is no successful lockfile-wide audit or vulnerability count. Cache/log files remain
private; no automatic dependency upgrade was made. Backend has no Composer manifest.

Installed direct versions: React/react-dom19.3.1, react-router-dom/react-router7.18.4,
Vite8.3.4, @vitejs/plugin-react6.1.2, playwright-core1.64.1. Hunter uses browser React,
BrowserRouter, static Apache build and PHP API; no React Server Components/SSR endpoints.

Primary-source spot assessment (not an exhaustive transitive audit):

| Advisory | Assessment |
|---|---|
| [Vite GHSA-rq7h-c2jc-7f22](https://github.com/vitejs/vite/security/advisories/GHSA-rq7h-c2jc-7f22) | Oct6 path issue fixed8.3.3; installed8.3.4 is outside affected range; issue also excludes Windows. |
| [Vite GHSA-vfpm-58rq-9qcg](https://github.com/vitejs/vite/security/advisories/GHSA-vfpm-58rq-9qcg) | Oct6 Wasm disclosure fixed8.3.3; installed8.3.4 outside affected range. |
| [Vite GHSA-9jrq-w75r-8gcw](https://github.com/vitejs/vite/security/advisories/GHSA-9jrq-w75r-8gcw) | Custom middleware cross-origin issue fixed8.3.3; installed8.3.4 outside affected range. |
| [Vite GHSA-fx2h-pf6j-xcff](https://github.com/vitejs/vite/security/advisories/GHSA-fx2h-pf6j-xcff) | Windows alternate-path disclosure fixed8.0.16; installed8.3.4 outside affected range. Apache serves the demo build, not a Vite dev server. |
| [Router GHSA-wrjc-x8rr-h8h6](https://github.com/remix-run/react-router/security/advisories/GHSA-wrjc-x8rr-h8h6) | External redirect issue fixed7.18.0; installed7.18.4 outside affected range. |
| [Router GHSA-337j-9hxr-rhxg](https://github.com/remix-run/react-router/security/advisories/GHSA-337j-9hxr-rhxg) | SSR constructor issue fixed7.18.0; installed7.18.4 outside range and app has no SSR. |
| [React GHSA-wx67-qw84-cm4g](https://github.com/react/react/security/advisories/GHSA-wx67-qw84-cm4g) | Server-function DoS concerns react-server-dom packages; app does not use RSC/server functions. Not a client React finding. |
| [Plugin GHSA-59pf-q495-3c99](https://github.com/vitejs/vite-plugin-react/security/advisories/GHSA-59pf-q495-3c99) | Oct15 critical advisory concerns @vitejs/plugin-rsc <0.5.38, not @vitejs/plugin-react. RSC package/architecture absent here. |

[Playwright's maintainer advisory page](https://github.com/microsoft/playwright/security/advisories)
showed no published advisories at inspection; this is not proof of no vulnerabilities.
Other direct/transitive packages are not cleared by these spot checks. Run a complete
`npm.cmd audit` from frontend when registry connectivity works and assess actual installed
affected ranges/reachable behavior before upgrading. Do not equate npm failure with zero issues.
