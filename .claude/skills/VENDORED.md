# Vendored skills

| Skill | Upstream | Revision | Path | License |
|---|---|---|---|---|
| `grill-me` | [mattpocock/skills](https://github.com/mattpocock/skills) | `49dd158d1076134a641b33efb035946536778336` | `skills/productivity/grill-me/SKILL.md` | MIT (`LICENSE-mattpocock-skills`) |
| `grilling` | [mattpocock/skills](https://github.com/mattpocock/skills) | `49dd158d1076134a641b33efb035946536778336` | `skills/productivity/grilling/SKILL.md` | MIT (`LICENSE-mattpocock-skills`) |
| `impeccable` | [pbakaus/impeccable](https://github.com/pbakaus/impeccable) | `c3bb418ee38ee49a6b999b7752c1368f3123a2bd` | `.claude/skills/impeccable/` | Apache-2.0 (`impeccable/LICENSE`, `impeccable/NOTICE.md`) |
| `ui-ux-pro-max` | [nextlevelbuilder/ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) | `b7d5353e0130feb8c5574ef1251f8a1db1873ccb` | `.claude/skills/ui-ux-pro-max/` | MIT (`ui-ux-pro-max/LICENSE`) |
| `make-interfaces-feel-better` | [jakubkrehel/make-interfaces-feel-better](https://github.com/jakubkrehel/make-interfaces-feel-better) | `35545ea1512ad59fa463e6b1f95ca9c052981fe6` | `skills/make-interfaces-feel-better/` | MIT (`make-interfaces-feel-better/LICENSE`) |

Local changes: `grilling/SKILL.md` ends with a marked "AllTrue" section; everything above it is upstream verbatim.
To update, fetch the files at a new upstream SHA, keep the local section, and change the revision here.

Local changes (UI skills): each of `impeccable`, `ui-ux-pro-max`, `make-interfaces-feel-better` ends with a marked "AllTrue (local addition)" section; everything above it is upstream verbatim.
Stripped from upstream: `impeccable/scripts/` (launcher downloads a binary at runtime; 1.1 MB font index; 563 KB bundled browser script) and `reference/{live,live-setup,generate,hooks,doctor}.md`; `ui-ux-pro-max/scripts/tests/` and `validate_data.py`; its brand/banner/slides siblings were not taken.
`design-taste-frontend` stays at `e988add20dab` (linked from `.cursor/skills/`, AllTrue appendix kept). Upstream `9b4bf937a1f6` rewrote it as tasteskill 2.5 with an asset bundle (effects, icons, scripts) and sibling references we do not vendor, so it was not bumped.
