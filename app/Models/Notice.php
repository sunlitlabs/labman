<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message for the admins, shown as a banner until someone dismisses it. */
class Notice extends Model
{
    protected $fillable = ['key', 'kind', 'title', 'body', 'url', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    /** Raise a notice; the same key does not nag again unless its content changed. */
    public static function raise(string $key, string $kind, string $title, ?string $body = null, ?string $url = null): self
    {
        $notice = self::query()->firstOrNew(['key' => $key]);
        $changed = ! $notice->exists || $notice->title !== $title || $notice->body !== $body;
        $notice->fill(['kind' => $kind, 'title' => $title, 'body' => $body, 'url' => $url]);
        if ($changed) {
            $notice->dismissed_at = null;
        }
        $notice->save();

        return $notice;
    }

    public static function resolve(string $key): void
    {
        self::query()->where('key', $key)->delete();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function open()
    {
        return self::query()->whereNull('dismissed_at')->orderBy('id')->get();
    }
}
