<?php

/**
 * IDE helper file for Lettr Laravel package.
 *
 * This file provides IDE autocompletion for macros registered by the package.
 * It is not loaded at runtime.
 */

namespace Illuminate\Mail {

    use Lettr\Laravel\Mail\LettrPendingMail;

    /**
     * @method LettrPendingMail lettr() Start a Lettr mail chain for sending templates.
     */
    class Mailer {}
}

namespace Illuminate\Support\Facades {

    use Lettr\Laravel\Mail\LettrPendingMail;

    /**
     * @method static LettrPendingMail lettr() Start a Lettr mail chain for sending templates.
     */
    class Mail {}
}
