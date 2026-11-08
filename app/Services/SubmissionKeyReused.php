<?php

namespace App\Services;

use RuntimeException;

/** Internal signal: a submission key was reused by a different user, action or set of details. */
class SubmissionKeyReused extends RuntimeException {}
