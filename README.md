# Inspec Export Plugin

An OJS plugin to export journal articles as JATS XML packages and deposit them to
[Inspec](https://www.theiet.org/publishing/inspec/), the IET's abstracting and indexing service.

## What it does

For each selected article the plugin builds a zip package containing:

- the article's JATS XML, and
- the article's PDF galley (referenced from the XML with a `<self-uri>` element).

Packages can either be downloaded for manual delivery, or deposited directly to Inspec's
SFTP endpoint. With **Automatic deposit** enabled, newly published articles are deposited
by a daily scheduled task.

## Requirements

- OJS 3.6 or newer.
- The [JATS Template plugin](https://github.com/pkp/jatsTemplate), which generates the JATS XML
  when an article has no JATS file of its own.
- The `league/flysystem-sftp-v3` library, bundled with OJS. If it is missing,
  the settings page will say so; install it with:

  ```bash
  composer -d lib/pkp require league/flysystem-sftp-v3
  ```

- To include article full text in generated JATS, `pdftotext` must be enabled in
  `config.inc.php` under `[search]`:

  ```
  index[application/pdf] = "/usr/bin/pdftotext %s -"
  ```

## Settings

Journal Settings → Distribution → Inspec, or the plugin's Import/Export page.

| Setting                              | Description                                                                                                                             |
|--------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| Only use uploaded JATS XML           | When enabled, articles without an uploaded JATS file are skipped rather than falling back to system-generated JATS.                     |
| Automatic deposit                    | Deposit newly published articles on the daily scheduled task.                                                                           |
| Journal Abbreviation                 | **Required.** Used as the leading part of every package and file name.                                                                  |
| Package and file naming scheme       | Either volume/issue/page or article number. The chosen scheme's metadata must be present or the export fails with an explanatory error. |
| Host, Port, Path, Username, Password | SFTP connection details. Port defaults to 22. The password is stored encrypted.                                                         |

Deposit actions only appear once host, username, and password are all set.

## Naming

Package and file names are built from the journal abbreviation plus the selected naming scheme,
lowercased with all non-alphanumeric characters removed:

```
jhs-12-3-45.zip
└── jhs-12-3-45/
    ├── jhs-12-3-45.pdf
    └── jhs-12-3-45.xml
```

Downloaded packages also carry a timestamp. When several articles are downloaded at once the
result is a zip of per-article zips — unpack it and deposit the individual article packages,
not the outer file.

## Deposit status

Each article records its deposit state, visible in the Status column of the export list:
Not deposited, Deposited, Failed, or Marked registered. Failures store the error message,
viewable by clicking the status.

## License

GNU General Public License v3. See [LICENSE](LICENSE).
