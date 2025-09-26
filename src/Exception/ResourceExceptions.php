<?php

/**
 * Backwards compatibility file for resource-related exceptions.
 *
 * @file
 *
 * Resource-related exception classes have been moved to individual files.
 * This file is kept for backwards compatibility.
 * Use the individual exception classes directly:
 * - MemoryExhaustedException
 * - VectorIndexCorruptedException.
 */

// Include the individual exception files for compatibility.
require_once __DIR__ . '/MemoryExhaustedException.php';
require_once __DIR__ . '/VectorIndexCorruptedException.php';
