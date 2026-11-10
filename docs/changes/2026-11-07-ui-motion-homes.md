## 2026-11-07 — feat(ui): motion kit + bolder Login, parent home and director home
<!-- release-notes: staff_update=staff-2026-11-07-ui-motion-homes -->
- Login, parent home (首頁) and director home (今天): quieter look (Login keeps its orange with a slow aurora drift), numbers count up, entrance blur-fade, one gold shine border on the main action (登入 / 繳費方式 / 去點名); parent home wording is gentler (本期學費, 待繳, 繳費方式). All motion is off under prefers-reduced-motion.
- New copy-paste motion kit `components/motion` (NumberTicker, BlurFade, ShineBorder; Inspira UI, MIT; CSS/rAF, no new package). Replaces `ui/number-ticker` and the motion-v enter on the director home.
