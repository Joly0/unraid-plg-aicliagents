# Third-party notices

The plugin code is licensed under the MIT License (see `LICENSE`). The plugin
also ships the third-party software listed below. Each item stays under its own
license. This file names the license of each item. The full license text and the
copyright line of each npm package are in the `LICENSE` file inside that package's
npm archive (the package name and version below identify it).

## 1. Web interface (bundled into `src/assets/ui`)

The web interface is built from `ui-build`. These are the production
dependencies, direct and indirect, taken from `ui-build/package-lock.json`.
Packages used only to build or test the interface (dev dependencies) do not ship
and are not listed.

Direct dependencies: `@codemirror/lang-html`, `@codemirror/lang-markdown`,
`@codemirror/language`, `@codemirror/language-data`, `@codemirror/state`,
`@codemirror/view`, `@uiw/react-codemirror`, `@xterm/addon-fit`,
`@xterm/addon-web-links`, `@xterm/xterm`, `dompurify`, `lucide-react`, `mermaid`,
`react`, `react-dom`, `react-markdown`, `rehype-sanitize`, `remark-gfm`.

Notes:

- `dompurify` is offered under "MPL-2.0 OR Apache-2.0". This project uses it under
  the Apache-2.0 option. No MPL-covered file is modified or copied.
- `khroma` has no license field in the lock file. Its upstream repository
  declares the MIT License.
- `robust-predicates` is in the public domain (Unlicense).

| Package | Version | License |
| :-- | :-- | :-- |
| @antfu/install-pkg | 1.1.0 | MIT |
| @babel/runtime | 7.29.2 | MIT |
| @braintree/sanitize-url | 7.1.2 | MIT |
| @chevrotain/types | 11.1.2 | Apache-2.0 |
| @codemirror/autocomplete | 6.20.3 | MIT |
| @codemirror/commands | 6.10.4 | MIT |
| @codemirror/lang-angular | 0.1.4 | MIT |
| @codemirror/lang-cpp | 6.0.3 | MIT |
| @codemirror/lang-css | 6.3.1 | MIT |
| @codemirror/lang-go | 6.0.1 | MIT |
| @codemirror/lang-html | 6.4.11 | MIT |
| @codemirror/lang-java | 6.0.2 | MIT |
| @codemirror/lang-javascript | 6.2.5 | MIT |
| @codemirror/lang-jinja | 6.0.1 | MIT |
| @codemirror/lang-json | 6.0.2 | MIT |
| @codemirror/lang-less | 6.0.2 | MIT |
| @codemirror/lang-liquid | 6.3.2 | MIT |
| @codemirror/lang-markdown | 6.5.0 | MIT |
| @codemirror/lang-php | 6.0.2 | MIT |
| @codemirror/lang-python | 6.2.1 | MIT |
| @codemirror/lang-rust | 6.0.2 | MIT |
| @codemirror/lang-sass | 6.0.2 | MIT |
| @codemirror/lang-sql | 6.10.0 | MIT |
| @codemirror/lang-vue | 0.1.3 | MIT |
| @codemirror/lang-wast | 6.0.2 | MIT |
| @codemirror/lang-xml | 6.1.0 | MIT |
| @codemirror/lang-yaml | 6.1.3 | MIT |
| @codemirror/language | 6.12.4 | MIT |
| @codemirror/language-data | 6.5.2 | MIT |
| @codemirror/legacy-modes | 6.5.3 | MIT |
| @codemirror/lint | 6.9.7 | MIT |
| @codemirror/search | 6.7.1 | MIT |
| @codemirror/state | 6.7.1 | MIT |
| @codemirror/theme-one-dark | 6.1.3 | MIT |
| @codemirror/view | 6.43.6 | MIT |
| @iconify/types | 2.0.0 | MIT |
| @iconify/utils | 3.1.4 | MIT |
| @lezer/common | 1.5.2 | MIT |
| @lezer/cpp | 1.1.6 | MIT |
| @lezer/css | 1.3.4 | MIT |
| @lezer/go | 1.0.1 | MIT |
| @lezer/highlight | 1.2.3 | MIT |
| @lezer/html | 1.3.13 | MIT |
| @lezer/java | 1.1.3 | MIT |
| @lezer/javascript | 1.5.4 | MIT |
| @lezer/json | 1.0.3 | MIT |
| @lezer/lr | 1.4.10 | MIT |
| @lezer/markdown | 1.7.1 | MIT |
| @lezer/php | 1.0.5 | MIT |
| @lezer/python | 1.1.19 | MIT |
| @lezer/rust | 1.0.2 | MIT |
| @lezer/sass | 1.1.0 | MIT |
| @lezer/xml | 1.0.6 | MIT |
| @lezer/yaml | 1.0.4 | MIT |
| @marijn/find-cluster-break | 1.0.3 | MIT |
| @mermaid-js/parser | 1.2.0 | MIT |
| @types/d3 | 7.4.3 | MIT |
| @types/d3-array | 3.2.2 | MIT |
| @types/d3-axis | 3.0.6 | MIT |
| @types/d3-brush | 3.0.6 | MIT |
| @types/d3-chord | 3.0.6 | MIT |
| @types/d3-color | 3.1.3 | MIT |
| @types/d3-contour | 3.0.6 | MIT |
| @types/d3-delaunay | 6.0.4 | MIT |
| @types/d3-dispatch | 3.0.7 | MIT |
| @types/d3-drag | 3.0.7 | MIT |
| @types/d3-dsv | 3.0.7 | MIT |
| @types/d3-ease | 3.0.2 | MIT |
| @types/d3-fetch | 3.0.7 | MIT |
| @types/d3-force | 3.0.10 | MIT |
| @types/d3-format | 3.0.4 | MIT |
| @types/d3-geo | 3.1.0 | MIT |
| @types/d3-hierarchy | 3.1.7 | MIT |
| @types/d3-interpolate | 3.0.4 | MIT |
| @types/d3-path | 3.1.1 | MIT |
| @types/d3-polygon | 3.0.2 | MIT |
| @types/d3-quadtree | 3.0.6 | MIT |
| @types/d3-random | 3.0.4 | MIT |
| @types/d3-scale | 4.0.9 | MIT |
| @types/d3-scale-chromatic | 3.1.0 | MIT |
| @types/d3-selection | 3.0.11 | MIT |
| @types/d3-shape | 3.1.8 | MIT |
| @types/d3-time | 3.0.4 | MIT |
| @types/d3-time-format | 4.0.3 | MIT |
| @types/d3-timer | 3.0.2 | MIT |
| @types/d3-transition | 3.0.9 | MIT |
| @types/d3-zoom | 3.0.8 | MIT |
| @types/debug | 4.1.13 | MIT |
| @types/estree | 1.0.8 | MIT |
| @types/estree-jsx | 1.0.5 | MIT |
| @types/geojson | 7946.0.16 | MIT |
| @types/hast | 3.0.5 | MIT |
| @types/mdast | 4.0.4 | MIT |
| @types/ms | 2.1.0 | MIT |
| @types/react | 19.2.14 | MIT |
| @types/trusted-types | 2.0.7 | MIT |
| @types/unist | 2.0.11 | MIT |
| @types/unist | 3.0.3 | MIT |
| @uiw/codemirror-extensions-basic-setup | 4.25.11 | MIT |
| @uiw/react-codemirror | 4.25.11 | MIT |
| @ungap/structured-clone | 1.3.3 | ISC |
| @upsetjs/venn.js | 2.0.0 | MIT |
| @xterm/addon-fit | 0.11.0 | MIT |
| @xterm/addon-web-links | 0.11.0 | MIT |
| @xterm/xterm | 5.5.0 | MIT |
| bail | 2.0.2 | MIT |
| ccount | 2.0.1 | MIT |
| character-entities | 2.0.2 | MIT |
| character-entities-html4 | 2.1.0 | MIT |
| character-entities-legacy | 3.0.0 | MIT |
| character-reference-invalid | 2.0.1 | MIT |
| codemirror | 6.0.2 | MIT |
| comma-separated-tokens | 2.0.3 | MIT |
| commander | 7.2.0 | MIT |
| commander | 8.3.0 | MIT |
| cose-base | 1.0.3 | MIT |
| cose-base | 2.2.0 | MIT |
| crelt | 1.0.7 | MIT |
| csstype | 3.2.3 | MIT |
| cytoscape | 3.34.0 | MIT |
| cytoscape-cose-bilkent | 4.1.0 | MIT |
| cytoscape-fcose | 2.2.0 | MIT |
| d3 | 7.9.0 | ISC |
| d3-array | 2.12.1 | BSD-3-Clause |
| d3-array | 3.2.4 | ISC |
| d3-axis | 3.0.0 | ISC |
| d3-brush | 3.0.0 | ISC |
| d3-chord | 3.0.1 | ISC |
| d3-color | 3.1.0 | ISC |
| d3-contour | 4.0.2 | ISC |
| d3-delaunay | 6.0.4 | ISC |
| d3-dispatch | 3.0.1 | ISC |
| d3-drag | 3.0.0 | ISC |
| d3-dsv | 3.0.1 | ISC |
| d3-ease | 3.0.1 | BSD-3-Clause |
| d3-fetch | 3.0.1 | ISC |
| d3-force | 3.0.0 | ISC |
| d3-format | 3.1.2 | ISC |
| d3-geo | 3.1.1 | ISC |
| d3-hierarchy | 3.1.2 | ISC |
| d3-interpolate | 3.0.1 | ISC |
| d3-path | 1.0.9 | BSD-3-Clause |
| d3-path | 3.1.0 | ISC |
| d3-polygon | 3.0.1 | ISC |
| d3-quadtree | 3.0.1 | ISC |
| d3-random | 3.0.1 | ISC |
| d3-sankey | 0.12.3 | BSD-3-Clause |
| d3-scale | 4.0.2 | ISC |
| d3-scale-chromatic | 3.1.0 | ISC |
| d3-selection | 3.0.0 | ISC |
| d3-shape | 1.3.7 | BSD-3-Clause |
| d3-shape | 3.2.0 | ISC |
| d3-time | 3.1.0 | ISC |
| d3-time-format | 4.1.0 | ISC |
| d3-timer | 3.0.1 | ISC |
| d3-transition | 3.0.1 | ISC |
| d3-zoom | 3.0.0 | ISC |
| dagre-d3-es | 7.0.14 | MIT |
| dayjs | 1.11.21 | MIT |
| debug | 4.4.3 | MIT |
| decode-named-character-reference | 1.3.0 | MIT |
| delaunator | 5.1.0 | ISC |
| dequal | 2.0.3 | MIT |
| devlop | 1.1.0 | MIT |
| dompurify | 3.4.12 | MPL-2.0 OR Apache-2.0 |
| es-toolkit | 1.49.0 | MIT |
| escape-string-regexp | 5.0.0 | MIT |
| estree-util-is-identifier-name | 3.0.0 | MIT |
| extend | 3.0.2 | MIT |
| hachure-fill | 0.5.2 | MIT |
| hast-util-sanitize | 5.0.2 | MIT |
| hast-util-to-jsx-runtime | 2.3.6 | MIT |
| hast-util-whitespace | 3.0.0 | MIT |
| html-url-attributes | 3.0.1 | MIT |
| iconv-lite | 0.6.3 | MIT |
| import-meta-resolve | 4.2.0 | MIT |
| inline-style-parser | 0.2.7 | MIT |
| internmap | 1.0.1 | ISC |
| internmap | 2.0.3 | ISC |
| is-alphabetical | 2.0.1 | MIT |
| is-alphanumerical | 2.0.1 | MIT |
| is-decimal | 2.0.1 | MIT |
| is-hexadecimal | 2.0.1 | MIT |
| is-plain-obj | 4.1.0 | MIT |
| katex | 0.16.47 | MIT |
| khroma | 2.1.0 | MIT |
| layout-base | 1.0.2 | MIT |
| layout-base | 2.0.1 | MIT |
| lodash-es | 4.18.1 | MIT |
| longest-streak | 3.1.0 | MIT |
| lucide-react | 0.475.0 | ISC |
| markdown-table | 3.0.4 | MIT |
| marked | 16.4.2 | MIT |
| mdast-util-find-and-replace | 3.0.2 | MIT |
| mdast-util-from-markdown | 2.0.3 | MIT |
| mdast-util-gfm | 3.1.0 | MIT |
| mdast-util-gfm-autolink-literal | 2.0.1 | MIT |
| mdast-util-gfm-footnote | 2.1.0 | MIT |
| mdast-util-gfm-strikethrough | 2.0.0 | MIT |
| mdast-util-gfm-table | 2.0.0 | MIT |
| mdast-util-gfm-task-list-item | 2.0.0 | MIT |
| mdast-util-mdx-expression | 2.0.1 | MIT |
| mdast-util-mdx-jsx | 3.2.0 | MIT |
| mdast-util-mdxjs-esm | 2.0.1 | MIT |
| mdast-util-phrasing | 4.1.0 | MIT |
| mdast-util-to-hast | 13.2.1 | MIT |
| mdast-util-to-markdown | 2.1.2 | MIT |
| mdast-util-to-string | 4.0.0 | MIT |
| mermaid | 11.16.0 | MIT |
| micromark | 4.0.2 | MIT |
| micromark-core-commonmark | 2.0.3 | MIT |
| micromark-extension-gfm | 3.0.0 | MIT |
| micromark-extension-gfm-autolink-literal | 2.1.0 | MIT |
| micromark-extension-gfm-footnote | 2.1.0 | MIT |
| micromark-extension-gfm-strikethrough | 2.1.0 | MIT |
| micromark-extension-gfm-table | 2.1.1 | MIT |
| micromark-extension-gfm-tagfilter | 2.0.0 | MIT |
| micromark-extension-gfm-task-list-item | 2.1.0 | MIT |
| micromark-factory-destination | 2.0.1 | MIT |
| micromark-factory-label | 2.0.1 | MIT |
| micromark-factory-space | 2.0.1 | MIT |
| micromark-factory-title | 2.0.1 | MIT |
| micromark-factory-whitespace | 2.0.1 | MIT |
| micromark-util-character | 2.1.1 | MIT |
| micromark-util-chunked | 2.0.1 | MIT |
| micromark-util-classify-character | 2.0.1 | MIT |
| micromark-util-combine-extensions | 2.0.1 | MIT |
| micromark-util-decode-numeric-character-reference | 2.0.2 | MIT |
| micromark-util-decode-string | 2.0.1 | MIT |
| micromark-util-encode | 2.0.1 | MIT |
| micromark-util-html-tag-name | 2.0.1 | MIT |
| micromark-util-normalize-identifier | 2.0.1 | MIT |
| micromark-util-resolve-all | 2.0.1 | MIT |
| micromark-util-sanitize-uri | 2.0.1 | MIT |
| micromark-util-subtokenize | 2.1.0 | MIT |
| micromark-util-symbol | 2.0.1 | MIT |
| micromark-util-types | 2.0.2 | MIT |
| ms | 2.1.3 | MIT |
| package-manager-detector | 1.7.0 | MIT |
| parse-entities | 4.0.2 | MIT |
| path-data-parser | 0.1.0 | MIT |
| points-on-curve | 0.2.0 | MIT |
| points-on-path | 0.2.1 | MIT |
| property-information | 7.2.0 | MIT |
| react | 19.2.4 | MIT |
| react-dom | 19.2.4 | MIT |
| react-markdown | 9.1.0 | MIT |
| rehype-sanitize | 6.0.0 | MIT |
| remark-gfm | 4.0.1 | MIT |
| remark-parse | 11.0.0 | MIT |
| remark-rehype | 11.1.2 | MIT |
| remark-stringify | 11.0.0 | MIT |
| robust-predicates | 3.0.3 | Unlicense |
| roughjs | 4.6.6 | MIT |
| rw | 1.3.3 | BSD-3-Clause |
| safer-buffer | 2.1.2 | MIT |
| scheduler | 0.27.0 | MIT |
| space-separated-tokens | 2.0.2 | MIT |
| stringify-entities | 4.0.4 | MIT |
| style-mod | 4.1.3 | MIT |
| style-to-js | 1.1.21 | MIT |
| style-to-object | 1.0.14 | MIT |
| stylis | 4.4.0 | MIT |
| tinyexec | 1.2.4 | MIT |
| trim-lines | 3.0.1 | MIT |
| trough | 2.2.0 | MIT |
| ts-dedent | 2.3.0 | MIT |
| unified | 11.0.5 | MIT |
| unist-util-is | 6.0.1 | MIT |
| unist-util-position | 5.0.0 | MIT |
| unist-util-stringify-position | 4.0.0 | MIT |
| unist-util-visit | 5.1.0 | MIT |
| unist-util-visit-parents | 6.0.2 | MIT |
| uuid | 14.0.1 | MIT |
| vfile | 6.0.3 | MIT |
| vfile-message | 4.0.3 | MIT |
| w3c-keyname | 2.2.8 | MIT |
| zwitch | 2.0.4 | MIT |

## 2. Secret-service daemon (`secret-service-src`, Go)

The secret-service daemon is a Go program. It is compiled into
`src/secret-service/secret-service-daemon`. It has one third-party module and uses
the Go standard library.

| Module | Version | License |
| :-- | :-- | :-- |
| github.com/godbus/dbus/v5 | v5.1.0 | BSD-2-Clause |
| Go standard library (linked into the binary) | go 1.23 toolchain | BSD-3-Clause |

godbus/dbus copyright line: "Copyright (c) 2013, Georg Reinke (<guelfey at gmail
dot com>), Google. All rights reserved."
Go standard library copyright line: "Copyright (c) 2009 The Go Authors. All rights
reserved."

## 3. Other bundled files

- `src/assets/icons` holds the logos of the third-party agents that the plugin can
  install. Each logo belongs to its owner. The plugin shows them only to identify
  the agent. They are not covered by the MIT License of this project.
- The PHP and shell code in `src` has no bundled third-party library.
  (`src/vendor` does not exist.)

The agents that the plugin downloads at install time (for example Claude Code or
Codex) are not part of this repository. Each one comes with its own license.
