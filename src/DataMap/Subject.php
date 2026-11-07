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
     * The key as the column type wants it: an integer for an integer key, else the string.
     */
    public function keyValue(): int|string
    {
        $model = new ($this->root->model);

        return $model->getKeyType() === 'int' && ctype_digit($this->id) ? (int) $this->id : $this->id;
    }
}
