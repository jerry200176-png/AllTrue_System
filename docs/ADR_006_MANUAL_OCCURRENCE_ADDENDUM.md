# ADR-006 addendum: manual occurrence scheduling (#211)

`StudentClass.scheduling_policy` is deliberately separate from billing `ScheduleMode`.
The default `auto_recurrence` keeps the existing fixed-schedule behavior. `manual_occurrence`
is an independent-session-only policy: historical and already-created future `ClassSession`
rows remain untouched, no forward generator or leave tail extension may create rows, and each
new future occurrence is created through `ManualSessionBookingService` and
`ClassSessionMaterializationService::upsertSlot`.

For booking, `RemainingSessions` remains consumption-derived. Available booking capacity is
`RemainingSessions - active future ClassSession rows`; booking reserves capacity but does not
deduct a lesson. Attendance/approval deducts the lesson, while cancellation or voiding a future
row releases the reservation. Shared `PackageID` courses are explicitly unsupported in v1.
