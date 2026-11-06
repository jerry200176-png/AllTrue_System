# Vendored skills

| Skill | Upstream | Revision | Path | License |
|---|---|---|---|---|
| `grill-me` | [mattpocock/skills](https://github.com/mattpocock/skills) | `49dd158d1076134a641b33efb035946536778336` | `skills/productivity/grill-me/SKILL.md` | MIT (`LICENSE-mattpocock-skills`) |
| `grilling` | [mattpocock/skills](https://github.com/mattpocock/skills) | `49dd158d1076134a641b33efb035946536778336` | `skills/productivity/grilling/SKILL.md` | MIT (`LICENSE-mattpocock-skills`) |
| `impeccable` | [pbakaus/impeccable](https://github.com/pbakaus/impeccable) | `c3bb418ee38ee49a6b999b7752c1368f3123a2bd` | `.claude/skills/impeccable/` | Apache-2.0 (`impeccable/LICENSE`, `impeccable/NOTICE.md`) |
| `web-design-guidelines` | [vercel-labs/web-interface-guidelines](https://github.com/vercel-labs/web-interface-guidelines) | `434b7f91364665f2f733b310ec54809bf8f37937` | `command.md` → `guidelines.md` | MIT (`web-design-guidelines/LICENSE`) |
| `ui-ux-pro-max` | [nextlevelbuilder/ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) | `b7d5353e0130feb8c5574ef1251f8a1db1873ccb` | `.claude/skills/ui-ux-pro-max/` | MIT (`ui-ux-pro-max/LICENSE`) |
| `make-interfaces-feel-better` | [jakubkrehel/make-interfaces-feel-better](https://github.com/jakubkrehel/make-interfaces-feel-better) | `35545ea1512ad59fa463e6b1f95ca9c052981fe6` | `skills/make-interfaces-feel-better/` | MIT (`make-interfaces-feel-better/LICENSE`) |

Local changes: `grilling/SKILL.md` ends with a marked "AllTrue" section; everything above it is upstream verbatim.
To update, fetch the files at a new upstream SHA, keep the local section, and change the revision here.

Local changes (UI skills): each of `impeccable`, `ui-ux-pro-max`, `make-interfaces-feel-better` ends with a marked "AllTrue (local addition)" section; everything above it is upstream verbatim, except that `ui-ux-pro-max/SKILL.md` drops the `${CLAUDE_PLUGIN_ROOT}/` prefix from its script paths (run from the repo root).
Stripped from upstream: `ui-ux-pro-max/data/{phosphor-icons-upstream,google-font-licenses,data-provenance}.json` (55k lines, unused by its scripts); `impeccable/scripts/` (launcher downloads a binary at runtime; 1.1 MB font index; 563 KB bundled browser script) and `reference/{live,live-setup,generate,hooks,doctor}.md`; `ui-ux-pro-max/scripts/tests/` and `validate_data.py`; its brand/banner/slides siblings were not taken.
`design-taste-frontend` stays at `e988add20dab` (linked from `.cursor/skills/`, AllTrue appendix kept). Upstream `9b4bf937a1f6` rewrote it as tasteskill 2.5 with an asset bundle (effects, icons, scripts) and sibling references we do not vendor, so it was not bumped.
