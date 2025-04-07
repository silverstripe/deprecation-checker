# Deprecation Changelog Generator

This tool generates the deprecation notice section of a changelog for a Silverstripe CMS release.

It also lets you know if you need to perform follow-up actions (e.g. if some removed API didn't have a deprecation notice).

**This tool is only intended for use by Silverstripe core committers or the Silverstripe Ltd CMS Squad**

## Setup

1. Clone this repo somewhere.
1. Run `composer install` to install its dependencies.
1. Choose where this tool will output its working data and finished results. If this is not your current working directory, see the note about `--dir` below.

## Usage

If you're unsure of usage at any time, use the `--help` flag to get more details about any command, or run the `bin/deprecation-checker list` command to see all available commands.

> [!NOTE]
> All of the commands accept a `--dir` option. This determines the directory that will be used for all output and must be the same directory across all commands.
> If this flag is omitted, the current working directory will be used.

1. Run `bin/deprecation-checker clone <fromConstraint> <toConstraint>`
    - For example `bin/deprecation-checker clone sink 5.4.x-dev 6.0.x-dev --dir=~/dump/deprecation-checks`
1. Run `bin/deprecation-checker generate`
    - For example `bin/deprecation-checker generate --dir=~/dump/deprecation-checks`
1. Run `bin/deprecation-checker print-actions`
    - For example `bin/deprecation-checker print-actions --dir=~/dump/deprecation-checks`

This tool uses the `composer` binary on your machine directly, so you shouldn't have any trouble with hitting API rate limits, etc.

## Limitations

- This tool doesn't check YAML files for changes to default values or file/fragment names even through these are in our [definition of public API](https://docs.silverstripe.org/en/project_governance/public_api/)
- This tool doesn't check calls to `$this->extend()` even though this is in our [definition of public API](https://docs.silverstripe.org/en/project_governance/public_api/)
- It's not feasible to get config from `get_extra_config()` for comparing.
- The parsing library used doesn't:
  - Evaluate some types correctly to FQCN due to https://github.com/code-lts/doctum/issues/76
  - Pick up on [enums](https://www.php.net/manual/en/language.types.enumerations.php)
  - Pick up on globally-scoped consts
  - Handle types on constants
  - Detect the `readonly` keyword for classes and properties. It does detect `@readonly` in PHPDocs though.
