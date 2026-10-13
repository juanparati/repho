# Rephó

A lightweight repository manager and proxy for [Composer](https://getcomposer.org/) and [npm](https://www.npmjs.com/).

![Screenshot](resources/img/screenshot.png)


> [!NOTE]
> For enterprise and medium/large projects, I recommend using [Private Packagist](https://packagist.com/).


## For whom?

- For developers that need to host a few packages on a private server.
- For offline environments. (You are developing a project during your plane/train trip, and you need to pull cached packages).


## Features

- Simple to setup.
- Simple to use.
- Doesn't require any RDMS, it can work only with a sqlite file.
- Ideal for small projects and/or small teams.
- Serves private npm packages built from Git tags (works with npm, pnpm, Yarn and Bun). No `npm publish` needed, no Node.js on the server.
- Optional Composer and npm proxies.
- Security audit provided by [Packagist API](https://packagist.org/security-advisories/) and the npm registry's advisories endpoint.
- It can keep copies of packages in a local directory.
- Slack and email notifications for security alerts.
- Lightweight, you can host it on a Raspberry Pi or the cheaper Laravel Cloud instance.
- It may be configured to work without running a queue worker


## Docker deployment

A dockerfile was provided. If you need to install additional libraries like aws/aws-sdk-php or resend/resend-php for send e-mails. You need to install the dependencies and run:

```sh
docker build -t repho:latest .
```

then run the container:

```sh
docker run -p 4431:443 \
  -p 8081:80 \
  -e INIT_DEFAULT_EMAIL=admin@example.com \
  -e INIT_DEFAULT_NAME=admin \
  -e INIT_DEFAULT_PASSWORD=secretpass123 \
  repho:latest
```

