<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\Auth;
use App\Services\View;
use Smarty\Smarty;
use voku\helper\AntiXSS;
use function microtime;
use function round;

abstract class BaseController
{
    /**
     * @var Smarty
     */
    protected Smarty $view;

    /**
     * @var User
     */
    protected User $user;

    /**
     * @var AntiXSS
     */
    protected AntiXSS $antiXss;

    /**
     * Construct page renderer
     */
    public function __construct()
    {
        $this->user = Auth::getUser();
        $this->antiXss = new AntiXSS();
    }

    /**
     * Get smarty
     */
    public function view(): Smarty
    {
        $this->view = View::getSmarty();

        if (View::$connection) {
            $this->view->assign(
                'queryLog',
                View::$connection
                    ->connection('default')
                    ->getQueryLog()
            )->assign(
                'optTime',
                round((microtime(true) - View::$beginTime) * 1000, 2)
            );
        }

        return $this->view;
    }
}
