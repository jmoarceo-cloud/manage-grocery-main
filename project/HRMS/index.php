<?php
// The HRMS "We're Hiring" landing page has been merged into the main
// site homepage (see the root index.php, #careers section) so there's
// only one public-facing homepage for the whole site now. This file
// stays here just so old links/bookmarks to HRMS/index.php still work.
header('Location: ../index.php#careers');
exit;