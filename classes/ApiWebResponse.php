<?php

/**
 * Creates ApiWeb response envelopes and per-object results.
 */
final class ApiWebResponse
{
    /**
     * Creates a successful answer envelope.
     *
     * @param array<int,array<string,mixed>> $results ApiWeb result entries.
     * @return array<string,mixed>
     */
    public static function answer(array $results): array
    {
        return [
            'Results' => $results,
            'Error' => null,
            'Key' => '',
            'Stop' => false,
        ];
    }

    /**
     * Creates a protocol-level error envelope.
     *
     * @param int $code ApiWeb error code.
     * @param string $message User-facing error message.
     * @param bool $stop Whether Unicorn should stop this operation.
     * @return array<string,mixed>
     */
    public static function protocolError(int $code, string $message, bool $stop = true): array
    {
        return [
            'Results' => [],
            'Error' => ['Code' => $code, 'Message' => $message],
            'Key' => '',
            'Stop' => $stop,
        ];
    }

    /**
     * Creates one result entry.
     *
     * @param array<string,mixed>|null $item Single response item.
     * @param array<int,mixed> $collection Response collection.
     * @param array<int,array<string,mixed>> $errors Object-specific errors.
     * @return array<string,mixed>
     */
    public static function result(?array $item = null, array $collection = [], array $errors = []): array
    {
        return [
            'Errors' => $errors,
            'Collection' => $collection,
            'Item' => $item,
        ];
    }

    /**
     * Creates one object-specific error.
     *
     * @param int $code ApiWeb error code.
     * @param string $message User-facing message.
     * @return array<string,mixed>
     */
    public static function error(int $code, string $message): array
    {
        return ['Code' => $code, 'Message' => $message];
    }
}

