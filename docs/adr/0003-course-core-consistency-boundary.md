---
status: Accepted
date: 2026-06-27
owner: Principal Architect
---
# ADR-0003: The Course Core is one consistency boundary

## Constitution link
Derives from CONSTITUTION.md Article I.1 (correctness first); Handbook P3.

## Context
Enrollment, Scheduling, Delivery and Billing must agree atomically that a session was delivered, a unit consumed, and paid-state updated. Splitting them into separate services would require distributed sagas over money/units — high risk for global inconsistency.

## Decision
Enrollment + Scheduling + Delivery + Billing remain **one transactional consistency boundary** ("Course Core"). Satellites (Payroll, Engagement, Communication, Parent-Portal, Support) are eventually-consistent event consumers.

## Consequences
+ Strong consistency where money/units demand it; no sagas.
+ Clear future microservice seams (split only along proven event boundaries).
− The Core stays a modular monolith longer; modularity enforced by contexts + CODEOWNERS, not network boundaries.

## References
ADR-0004, ADR-0008. Handbook §8 (microservice boundaries).
