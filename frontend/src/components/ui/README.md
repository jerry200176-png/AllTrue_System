

## Overlay and data parts (K1b, #4989)

Direction (Founder 2026-11-07, 高級簡約): hairline borders, soft `bg-foreground/30` overlay, motion 200 ms or less, none under `prefers-reduced-motion`.

- `dialog/`: modal Dialog with focus trap, Esc, `DialogTitle` and `DialogDescription` for the aria name.
- `alert-dialog/`: destructive confirm; `AlertDialogCancel` and `AlertDialogAction` (`variant="destructive"`), no outside-click close.
