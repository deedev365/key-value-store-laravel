<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\InvalidBodyException;
use App\ValueObjects\WriteBody;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * The write envelope — a single-property JSON object whose property name is
 * the storage key — is the same for a POST that appends a version and a PUT
 * that corrects one, so the one place that turns a failed parse into the
 * validator's vocabulary lives here rather than in both requests.
 *
 * validateWriteBody() is called *from* withValidator() rather than replacing
 * it: each endpoint has its own checks to run once the body is understood,
 * and it returns the parsed body so those checks can read it without a second
 * parse or a second failure message.
 */
trait ParsesWriteBody
{
    private ?WriteBody $parsed = null;

    public function body(): WriteBody
    {
        return $this->parsed ??= $this->parseWriteBody();
    }

    /**
     * The parsed body, or null once the reason it could not be parsed has been
     * handed to the validator.
     */
    protected function validateWriteBody(ValidatorContract $validator): ?WriteBody
    {
        try {
            return $this->parsed = $this->parseWriteBody();
        } catch (InvalidBodyException $e) {
            $validator->errors()->add($e->field, $e->getMessage());

            return null;
        }
    }

    /**
     * @throws InvalidBodyException
     */
    private function parseWriteBody(): WriteBody
    {
        return WriteBody::fromJson(
            $this->getContent(),
            (int) config('kvstore.max_value_depth'),
        );
    }
}
