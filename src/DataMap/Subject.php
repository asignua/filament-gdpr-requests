<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Support\Pseudonym;

/**
 * The data subject of a request: an explicit key (the subject model + its id), the e-mail only as a second key.
 */
final readonly class Subject
{
    public string $id;

    public ?string $email;

    public function __construct(public PersonalData $root, string|int $id, ?string $email = null)
    {
        $this->id = (string) $id;
        $this->email = Pseudonym::normalizeEmail($email);
    }

    /**
     * The key as the column type wants it: an integer for an integer key, else the string. A cast to int saturates, so a
     * number past the range stays text and matches nothing, instead of becoming the last row of the table.
     */
    public function keyValue(): int|string
    {
        $model = new ($this->root->model);

        return $model->getKeyType() === 'int' && ctype_digit($this->id) && self::fitsInteger($this->id) ? (int) $this->id : $this->id;
    }

    /**
     * An integer key no PHP integer can hold. It matches no row on any driver: Postgres would abort the transaction over
     * the comparison, and MariaDB's unsigned keys would reach rows PHP cannot name.
     */
    public function overflows(): bool
    {
        return is_string($this->keyValue()) && (new ($this->root->model))->getKeyType() === 'int' && ctype_digit($this->id);
    }

    /**
     * @param string $digits only digits, no leading zeros
     */
    public static function fitsInteger(string $digits): bool
    {
        $top = (string) PHP_INT_MAX;

        return strlen($digits) < strlen($top) || (strlen($digits) === strlen($top) && strcmp($digits, $top) <= 0);
    }
}
