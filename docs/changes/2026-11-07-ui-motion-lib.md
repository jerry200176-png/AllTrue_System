## 2026-11-07 — feat(ui): motion component library (split from #5027)
<!-- release-notes: silent_ship=silent-2026-11-07-ui-motion-lib -->
<!-- silent-reason: new components only, not used by any page yet; #5027 adopts them -->
- Adds `frontend/src/components/motion/`: BlurFade, NumberTicker, ShineBorder, SlidingIndicator, TextReveal, LogoReveal, AuroraBackground, LoginFx and the Silk WebGL background (`fx/`), each with a reduced-motion fallback. Licences are vendored (vue-bits, inspira-ui).
- These files are byte-identical to #5027's R3-reviewed head `be65bb46e`. They are split out so that #5027 fits the 700-logic-line limit.
