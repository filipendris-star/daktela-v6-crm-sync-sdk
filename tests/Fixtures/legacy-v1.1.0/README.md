# v1.1.0 example configuration — frozen

A byte-for-byte copy of `config/example/` as it shipped in v1.1.0, kept as a
regression fixture. `tests/Regression/LegacyV110DeploymentTest.php` loads it to
prove that a deployment written against 1.1.0 still runs on this release.

**Do not "fix" anything in here.** The mistakes are the point — notably
`mappings/activities.yaml` carrying `lookup_field: name`, which the SDK's own
example shipped and which every config derived from it therefore carries. If a
change to the loader makes this fixture stop loading, that change breaks real
deployments.

Regenerate only from the v1.1.0 tag:

    git show v1.1.0:config/example/sync.yaml
