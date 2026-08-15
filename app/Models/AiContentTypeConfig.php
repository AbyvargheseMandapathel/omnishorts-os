<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiContentTypeConfig extends Model
{
    protected $fillable = ['user_id', 'content_type', 'role', 'ai_connection_id'];

    // Named aiConnection() (not connection()) — Model::$connection is a real
    // property on the base class, and inside the class PHP reads it directly,
    // bypassing Eloquent's __get magic.
    public function aiConnection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class, 'ai_connection_id');
    }

    /**
     * Resolve the active connection configured for a user's content type +
     * role, or null when unconfigured / inactive / not assigned.
     */
    public static function resolve(int $userId, string $contentType, string $role): ?AiConnection
    {
        $config = static::query()
            ->where('user_id', $userId)
            ->where('content_type', $contentType)
            ->where('role', $role)
            ->with('aiConnection')
            ->first();

        $connection = $config?->aiConnection;
        if (! $connection || ! $connection->is_active || ! $connection->isAssignedTo($contentType)) {
            return null;
        }

        return $connection;
    }
}
