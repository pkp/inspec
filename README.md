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

Deposits are queued: clicking Deposit (or the scheduled task running) dispatches one job
per article, which builds that article's package and uploads it. The request returns as
soon as the jobs are queued, so a slow endpoint never blocks the browser, and each
article's outcome is recorded against it individually.

## Versioning

**Inspec only indexes the first published version of an article.** Any subsequent versions are
excluded from their feed, and they match articles by DOI otherwise.

The plugin is built around that rule:

- An article is deposited **once**, when it is first published, and is never re-deposited.
  Publishing, versioning, or unpublishing a later version does not make it depositable again.
- Articles are always listed and deposited at the **submission** level, even on journals that
  assign a separate DOI to each version. Individual versions are never deposited as separate
  objects, so the export page shows a single list of articles rather than a per-version list.

A consequence worth being aware of: if a later version corrects the original, Inspec will
continue to index the uncorrected first version. There is no mechanism to push the correction.

## Requirements

- OJS 3.6 or newer.
- A way of running queued jobs, since deposits are dispatched as jobs: either OJS's
  scheduled `ProcessQueueJobs` task (which runs with the rest of the scheduler) or a
  dedicated worker, `php lib/pkp/tools/jobs.php work`.
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
| Package and file naming scheme       | Either volume/issue/page or article number. The chosen scheme's metadata must be present or the export fails with an explanatory error. |
| Host, Port, Path, Username, Password | SFTP connection details. Port defaults to 22. The password is stored encrypted.                                                         |

The SFTP account is optional — a journal can use Export to download packages and deliver
them manually instead — but partially filling it in is not: either all of host, username,
and password, or none. Automatic deposit requires a complete account. Deposit actions only
appear once host, username, and password are all set.

## Naming

Package and file names are built from the journal abbreviation (Journal Settings → Masthead),
falling back to the journal's URL path when no abbreviation is set, plus the selected naming
scheme, lowercased with all non-alphanumeric characters removed:

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
Not deposited, Submitted (queued, not yet delivered), Deposited, Failed, or Marked
registered. Failures store the error message, viewable by clicking the status.

## License

GNU General Public License v3. See [LICENSE](LICENSE).
