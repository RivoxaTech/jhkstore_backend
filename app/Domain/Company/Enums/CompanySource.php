<?php

namespace App\Domain\Company\Enums;

enum CompanySource: string
{
    case PublicForm = 'public_form';
    case Admin = 'admin';
}
