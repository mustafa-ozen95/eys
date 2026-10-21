<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Yetkilendirme: soyut taban sınıf; kendisi bir uca bağlı değildir.
 * Yetki kararı her controller'ın kendi HasMiddleware tanımında verilir.
 */
abstract class Controller
{
    //
}
