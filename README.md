# rakko-inc/laravel-graceful-schedule-worker

A lightweight Laravel 6 & 7 package that turns `schedule:run` into a long-running,
gracefully-stoppable worker.

## Overview

Run:

```shell
php artisan schedule:graceful-work
```

The command:

* Spawns `php artisan schedule:run` at the start of every minute.
* Streams the child process output directly to your console.
* Listens for `SIGINT` / `SIGTERM` (e.g., `Ctrl+C`, `kill`) and exits **gracefully**
  after stopping any running task.

Optional flag:

```shell
php artisan schedule:graceful-work --run-output-file=/path/to/schedule.log
```

## Demo

A ready-to-run sample application is located in the `demo/` directory.

### Start server with Docker

```shell
$ cd demo
$ docker compose up
[+] Running 1/1
 ✔ Container demo-php-1  Recreated                                                                                                                                                                                                            0.1s
Attaching to php-1
php-1  | Running scheduled tasks.
php-1  | Running scheduled command: '/usr/local/bin/php' 'artisan' hello >> '/app/storage/logs/scheduler.log' 2>&1
php-1  | [2025-05-07 16:28:00] local.INFO: hello start from Scheduler.
php-1  | Hello World!
php-1  | [2025-05-07 16:28:00] local.INFO: Hello World!
php-1  | [2025-05-07 16:28:00] local.INFO: hello successful.
php-1  | [2025-05-07 16:28:00] local.INFO: hello finished.

```
