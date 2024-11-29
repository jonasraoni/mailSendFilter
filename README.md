[![OJS compatibility](https://img.shields.io/badge/ojs-3.3-brightgreen)](https://github.com/pkp/ojs/tree/stable-3_3_0)
[![OMP compatibility](https://img.shields.io/badge/omp-3.3-brightgreen)](https://github.com/pkp/omp/tree/stable-3_3_0)
[![OPS compatibility](https://img.shields.io/badge/ops-3.3-brightgreen)](https://github.com/pkp/ops/tree/stable-3_3_0)
![GitHub release](https://img.shields.io/github/v/release/jonasraoni/mailSendFilter?include_prereleases&label=latest%20release&filter=v1*)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/jonasraoni/mailSendFilter)
![License type](https://img.shields.io/github/license/jonasraoni/mailSendFilter)
![Number of downloads](https://img.shields.io/github/downloads/jonasraoni/mailSendFilter/total)

# Mail Send Filter Plugin

## About

The plugin allows you to avoid sending emails to certain user accounts based on a couple of settings and rules, which is useful to reduce bounced emails and avoid your mail server from being added to a block list by other mail servers.

If you need support for older OJS releases, see the [available branches](https://github.com/jonasraoni/mailSendFilter/branches).

## Installation Instructions

We recommend installing this plugin using the Plugin Gallery within OJS. Log in
with administrator privileges, navigate to `Settings` > `Website` > `Plugins`, and
choose the Plugin Gallery. Find the `Mail Send Filter Plugin` there and install it.

> If for some reason, you need to install it manually:
> - Download the latest release (attention to the OJS version compatibility) or from GitHub (attention to grab the code from the right branch).
> - Create the folder `plugins/generic/mailSendFilter` and place the plugin files in it.
> - Run the command `php lib/pkp/tools/installPluginVersion.php plugins/generic/mailSendFilter/version.xml` at the main OJS folder, this will ensure the plugin is installed/upgraded properly (e.g. new fields might be added to the database).

After installing and enabling the plugin, access its settings to ensure everything fits your expectations, the plugin has some default values.

## Notes

- This is a site-wide plugin, which means its settings are shared across all the journals of an installation.
- It's possible to download a list containing all the blocked emails and the reason why they were blocked through the "Download blocked emails" link. Notice that when accessing the plugin from a journal/press/server you'll only emails from users which are assigned to the given context. You may also access the plugin settings from a site context and all blocked users will be displayed.

## License

This plugin is licensed under the GNU General Public License v3. See the
file LICENSE for the complete terms of this license.

## System Requirements

- OJS 3.3.0-X.
- External internet access to download the list of disposable domain servers.

## Contact/Support

If you have issues, please use the issues tracker (https://github.com/jonasraoni/mailSendFilter/issues).