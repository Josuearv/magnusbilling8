<?php

/**
 * Actions of module "Site" - ExtJS Boot & Environment Provider.
 *
 * MagnusBilling <info@magnusbilling.com>
 * 05/06/2013
 */

class SiteController extends Controller
{
    public function init()
    {
        $this->controllerName         = 'site';
        $this->addAuthorizedNoSession = 'site';
        $this->config                 = LoadConfig::getConfig();

        // Limpia sesión expirada de forma silenciosa sin emitir JSON de error
        $startSession = strlen(session_id()) < 1 ? session_start() : null;
        if (
            isset($this->config['global']['session_timeout']) &&
            (int) $this->config['global']['session_timeout'] > 60 &&
            isset(Yii::app()->session['session_start']) &&
            time() > (int) Yii::app()->session['session_start'] + (int) $this->config['global']['session_timeout']
        ) {
            Yii::app()->session->clear();
            Yii::app()->session->destroy();
        }
    }

    public function actionIndex()
    {
        if (! headers_sent()) {
            header('Content-Type: application/javascript; charset=utf-8');
        }

        echo 'window.CSRF_TOKEN_NAME = ' . json_encode(Yii::app()->request->csrfTokenName) . ';' . PHP_EOL;
        echo 'window.CSRF_TOKEN = ' . json_encode(Yii::app()->request->csrfToken) . ';' . PHP_EOL;

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $modelUser = User::model()->find('company_website LIKE :key', [':key' => $host]);

        if (isset($modelUser->id)) {
            echo 'window.agentTitle = ' . json_encode($modelUser->company_name) . ';' . PHP_EOL;
            echo 'window.agentId = ' . json_encode((int) $modelUser->id) . ';' . PHP_EOL;
        }

        if (isset($_GET['paypal'])) {
            exit(isset($this->config['global']['paypal-softphone']) ? (string) $this->config['global']['paypal-softphone'] : '0');
        }

        if (isset($_GET['callback'])) {
            exit(isset($this->config['global']['callback-softphone']) ? (string) $this->config['global']['callback-softphone'] : '0');
        }

        $base_language = ! empty($this->config['global']['base_language']) ? $this->config['global']['base_language'] : 'en';
        echo 'window.lang = ' . json_encode($base_language) . ';' . PHP_EOL;
        Yii::app()->session['language'] = $base_language;
        Yii::app()->setLanguage($base_language);

        $template = ! empty($this->config['global']['template']) ? $this->config['global']['template'] : 'black-neptune';
        echo 'window.theme = ' . json_encode($template) . ';' . PHP_EOL;
        echo 'window.theme_color = ' . json_encode(strtok($template, '-')) . ';' . PHP_EOL;
        Yii::app()->session['theme'] = $template;

        $layout = isset($this->config['global']['layout']) ? (int) $this->config['global']['layout'] : 0;
        echo 'window.layout = ' . json_encode($layout) . ';' . PHP_EOL;
        Yii::app()->session['layout'] = $layout;

        $disableMobileTemplate = isset($this->config['global']['disable_mobile_template'])
            ? (int) $this->config['global']['disable_mobile_template']
            : 0;
        echo 'window.disableMobileTemplate = ' . json_encode($disableMobileTemplate === 1) . ';' . PHP_EOL;
        if ($disableMobileTemplate === 1) {
            echo "Object.defineProperty(window, 'isTablet', { configurable: true, get: function () { return false; }, set: function () {} });" . PHP_EOL;
            echo "Object.defineProperty(window, 'isMobileLayout', { configurable: true, get: function () { return false; }, set: function () {} });" . PHP_EOL;
            echo "if (document.querySelector('meta[name=viewport]')) { document.querySelector('meta[name=viewport]').setAttribute('content', 'width=device-width, initial-scale=1, user-scalable=yes'); }" . PHP_EOL;
        }

        $wallpaper = ! empty($this->config['global']['wallpaper']) ? $this->config['global']['wallpaper'] : 'Azul';
        echo 'window.wallpaper = ' . json_encode($wallpaper) . ';' . PHP_EOL;
        Yii::app()->session['wallpaper'] = $wallpaper;
        echo 'window.colorMenu = ' . json_encode($this->config['global']['color_menu'] ?? '') . ';' . PHP_EOL;
        echo 'window.moduleExtra = ' . json_encode($this->config['global']['module_extra'] ?? '') . ';' . PHP_EOL;
        echo 'window.module2Extra = ' . json_encode($this->config['global']['module_extra2'] ?? '') . ';' . PHP_EOL;
        echo 'window.module3Extra = ' . json_encode($this->config['global']['module_extra3'] ?? '') . ';' . PHP_EOL;

        $reCaptchaKey = isset($this->config['global']['reCaptchaKey']) &&
            isset($this->config['global']['reCaptchaSecret']) &&
            strlen($this->config['global']['reCaptchaSecret']) > 10 &&
            strlen($this->config['global']['reCaptchaKey']) > 10
            ? $this->config['global']['reCaptchaKey']
            : "";
        echo 'window.reCaptchaKey = ' . json_encode($reCaptchaKey) . ';' . PHP_EOL;

        $parseSize = function ($val) {
            $val = trim((string) $val);
            if (empty($val)) {
                return 0;
            }
            $last = strtolower($val[strlen($val) - 1]);
            $num  = (float) $val;
            switch ($last) {
                case 'g':
                    $num *= 1024 * 1024 * 1024;
                    break;
                case 'm':
                    $num *= 1024 * 1024;
                    break;
                case 'k':
                    $num *= 1024;
                    break;
            }
            return (int) $num;
        };

        $iniUpload   = ini_get('upload_max_filesize') ?: '2M';
        $iniPost     = ini_get('post_max_size') ?: '8M';
        $uploadBytes = $parseSize($iniUpload);
        $postBytes   = $parseSize($iniPost);
        $maxBytes    = ($postBytes > 0 && $postBytes < $uploadBytes) ? $postBytes : $uploadBytes;
        if ($maxBytes <= 0) {
            $maxBytes = 2 * 1024 * 1024;
        }

        $displaySize = ($maxBytes >= 1048576)
            ? round($maxBytes / 1048576) . 'M'
            : round($maxBytes / 1024) . 'K';

        echo 'window.uploadFaxFilesize = ' . json_encode($displaySize) . ';' . PHP_EOL;
        echo 'window.uploadFaxFilesizeBytes = ' . json_encode($maxBytes) . ';' . PHP_EOL;
        echo 'window.uploadFaxFilesizebites = ' . json_encode((string) round($maxBytes / 1048576)) . ';' . PHP_EOL;
        echo 'window.show_signup_button = ' . json_encode((int) ($this->config['global']['show_signup_button'] ?? 0)) . ';' . PHP_EOL;
        echo 'window.auto_generate_user_signup = ' . json_encode((string) ($this->config['global']['auto_generate_user_signup'] ?? '')) . ';' . PHP_EOL;
        echo 'window.enable_signup = ' . json_encode((int) ($this->config['global']['enable_signup'] ?? 0)) . ';' . PHP_EOL;

        if (isset($this->config['global']['login_header']) && strlen($this->config['global']['login_header']) > 0) {
            echo 'window.loginheader = ' . json_encode($this->config['global']['login_header']) . ';' . PHP_EOL;
        }

        $signupAutoPass = isset($this->config['global']['signup_auto_pass']) ? (int) $this->config['global']['signup_auto_pass'] : 0;
        if ($signupAutoPass > 5) {
            $pass = Util::generatePassword($signupAutoPass, true, true, true, false);
        } else {
            $pass = 0;
        }
        echo 'window.signup_auto_pass = ' . json_encode($pass) . ';' . PHP_EOL;
        echo 'window.backgroundColor = ' . json_encode($this->config['global']['backgroundColor'] ?? '#1b1e23') . ';' . PHP_EOL;
        echo 'window.cn = ' . json_encode($this->config['global']['cn'] ?? '') . ';' . PHP_EOL;
        echo 'window.cl = ' . json_encode($this->config['global']['cl'] ?? '') . ';' . PHP_EOL;
        echo 'window.default_codes = ' . json_encode($this->config['global']['default_codeds'] ?? '') . ';' . PHP_EOL;
        echo 'window.global_record_calls = ' . json_encode((string) ($this->config['global']['global_record_calls'] ?? '0')) . ';' . PHP_EOL;
        echo 'window.default_prefix_rule = ' . json_encode((string) ($this->config['global']['default_prefix_rule'] ?? '')) . ';' . PHP_EOL;
        echo 'window.logged = ' . json_encode(! empty(Yii::app()->session['logged'])) . ';' . PHP_EOL;

        try {
            $sql          = "SELECT * FROM pkg_module_extra t JOIN pkg_module a ON t.id_module = a.id";
            $modele_extra = Yii::app()->db->createCommand($sql)->queryAll();
            foreach ($modele_extra as $key => $value) {
                if (isset($value['type']) && $value['type'] == 'form' && ! empty($value['description'])) {
                    $module = $value['module'];
                    preg_match_all('/"name":"(\D*)",/', $value['description'], $output_array);
                    $i = 0;
                    if (! empty($output_array[1])) {
                        foreach ($output_array[1] as $key => $value2) {
                            $_SESSION['module_extra'][$module][$i] = $value2;
                            $i++;
                        }
                    }
                    echo 'window.module_extra_form_' . $value['module'] . ' = ' . json_encode($value['description']) . ';' . PHP_EOL;
                }
            }
        } catch (Exception $e) {
            // No interrumpir la carga si la tabla no existe o falla la consulta
        }
    }
}
