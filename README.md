# Deprecation Changelog Generator

This tool generates the deprecation notice section of a changelog for a Silverstripe CMS release.

It also lets you know if you need to perform follow-up actions (e.g. if some removed API didn't have a deprecation notice).

**This tool is only intended for use by Silverstripe core committers or the Silverstripe Ltd CMS Squad**

## Setup

1. Clone this repo somewhere.
1. Run `composer install` to install its dependencies.
1. Set up a directory somewhere that this tool will use for cloning Silverstripe CMS into and for outputting any long-form data.

## Usage

If you're unsure of usage at any time, use the `--help` flag to get more details about any command, or run the `bin/deprecation-changelog-generator list` command to see all available commands.

1. Run `bin/deprecation-changelog-generator clone <recipe> <fromConstraint> <toConstraint>`
    - If you were not already in the directory you want it to dump data into, include `--dir=<path/to/that/directory>`
    - The directory can either be absolute, or relative to your current directory.
    - For example `bin/deprecation-changelog-generator clone sink 5.4.x-dev 6.0.x-dev --dir=~/dump/changelog`
1. Run `bin/deprecation-changelog-generator generate`
    - If you were not already in the directory from the previous step, include `--dir=<path/to/that/directory>`
    - The directory can either be absolute, or relative to your current directory.
    - For example `bin/deprecation-changelog-generator generate --dir=~/dump/changelog`

This tool uses the `composer` binary on your machine directly, so you shouldn't have any trouble with hitting API rate limits, etc.

## Limitations

- The parsing library used doesn't currently pick up on [enums](https://www.php.net/manual/en/language.types.enumerations.php)
- The parsing library used doesn't currently pick up on globally-scoped consts
- Due to https://github.com/code-lts/doctum/issues/76 types may not evaluate completely to FQCN
- It's not feasible to get config from `get_extra_config()` for comparing.
