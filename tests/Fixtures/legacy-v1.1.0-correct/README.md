# v1.1.0 config that got `lookup_field` right — frozen

The v1.1.0 example with ONE change: `mappings/activities.yaml` names the CRM-side
field (`external_id`) instead of the cc_field the shipped example used.

Not every 1.1.0 deployment copied the example's mistake, and this is the case that
actually exercises the upgrade path for a WORKING activity export — the entity
stays enabled, so the state-store guard and the seeding rail are both live. The
sibling `legacy-v1.1.0/` fixture cannot reach them: its activity entity is faulted
and disabled by the bad mapping.

Do not otherwise modernise it.
