"""Regression contracts for the main-event convergence scheduler."""

import unittest


def should_dispatch(*, active_ci, recent_dispatch, deploy_present):
    """Only request CI when no exact-main run is active or already downstream."""

    return not (active_ci or recent_dispatch or deploy_present)


class AutonomousConvergenceTest(unittest.TestCase):
    def test_dispatches_when_merge_left_no_exact_main_evidence(self):
        self.assertTrue(should_dispatch(active_ci=False, recent_dispatch=False, deploy_present=False))

    def test_does_not_duplicate_active_or_recent_ci(self):
        self.assertFalse(should_dispatch(active_ci=True, recent_dispatch=False, deploy_present=False))
        self.assertFalse(should_dispatch(active_ci=False, recent_dispatch=True, deploy_present=False))

    def test_does_not_dispatch_when_deploy_run_exists(self):
        self.assertFalse(should_dispatch(active_ci=False, recent_dispatch=False, deploy_present=True))


if __name__ == "__main__":
    unittest.main()
