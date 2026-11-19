<?php

namespace App\Inventory;

/** Turns LabMan's free-text names into names Ansible accepts. */
final class Slug
{
    /** Host names keep letters, digits, "_", "." and "-"; everything else becomes "-". */
    public static function host(string $name): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9_.\-]+/', '-', trim($name)), '-.');

        return $slug === '' ? 'host' : $slug;
    }

    /** Group names keep letters, digits and "_" (the only characters Ansible is happy with everywhere). */
    public static function group(string $name): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9_]+/', '_', trim($name)), '_');

        return $slug === '' ? 'group' : $slug;
    }
}
