# AllTrue Codex adapter

Load [`governance/PORTFOLIO_AGENT_CONTRACT.md`](governance/PORTFOLIO_AGENT_CONTRACT.md)
before acting. It is the portable minimum for this repository; this adapter
must not duplicate or override it. If the overlay or required governance
context is unavailable, stop and report the missing context.

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
