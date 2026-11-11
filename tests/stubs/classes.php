<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Files.SideEffects

final class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_date = '0000-00-00 00:00:00';
    public string $post_date_gmt = '0000-00-00 00:00:00';
    public int $post_parent = 0;
    public string $post_name = '';

    public function __construct(object $post)
    {
        foreach (get_object_vars($post) as $key => $value) {
            $this->$key = $value;
        }
    }
}

final class WP_Site
{
    public string $blog_id = '1';

    public function __construct(int $id)
    {
        $this->blog_id = (string) $id;
    }
}

class WP_CLI
{
    public static function add_command(string $name, object $command): void
    {
        WpStubs::$cliCommands[$name] = $command;
    }

    public static function log(string $message): void
    {
        WpStubs::$cliOutput[] = $message;
    }

    public static function line(string $message = ''): void
    {
        WpStubs::$cliOutput[] = $message;
    }

    public static function success(string $message): void
    {
        WpStubs::$cliOutput[] = 'Success: ' . $message;
    }

    public static function warning(string $message): void
    {
        WpStubs::$cliOutput[] = 'Warning: ' . $message;
    }

    /**
     * @return never
     */
    public static function error(string $message): void
    {
        WpStubs::$cliOutput[] = 'Error: ' . $message;
        throw new WpCliError($message);
    }
}
