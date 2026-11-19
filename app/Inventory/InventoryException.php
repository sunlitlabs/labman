<?php

namespace App\Inventory;

use RuntimeException;

/** A problem reading, writing or syncing the inventory, worded for the admin. */
final class InventoryException extends RuntimeException {}
