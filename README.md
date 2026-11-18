# Privacy

Answer a WordPress personal data export or erasure request for your plugin's
own tables, from arrays, without handing over a column nobody should have.

## What it does

WordPress's Export and Erase Personal Data tools call an exporter and an
eraser for every group of data each plugin holds, a page at a time, each with
its own array shape. Written by hand that is two callbacks per table, each
paging, labelling and stringifying the same way — and each one a place to
export a download token with `SELECT *`.

Here a group is an array: its label, how to find a page of rows for an
address, which columns to show under which label, and how to erase them. The
small pieces every erasure path needs come with it: finding an address in a
queued payload however JSON escaped it, an audit row that names nobody, and
consent that is never "no" when nobody asked.

## Features

* Register a plugin's exporters and erasers with WordPress in one call, from arrays
* Export only the columns you name, under labels in the site's language
* Show a stored consent as Yes, No or Not asked — never "no" for a null
* Let an add-on add its own tables to the same export through a filter
* Find an address in a pending email or webhook payload, in any case and as `json_encode()` wrote it
* Strip every address out of a bounce or an upstream error before it is stored
* Record who an erasure was for as a short keyed digest, and what it did as "2 orders, 1 licences"

## Installation

```bash
composer require sugarcommerce/privacy
```

## Quick start

A shop's orders, exported and anonymised when a customer asks:

```php
use SugarCommerce\Privacy\PersonalData;

( new PersonalData( 'myshop', __( 'My Shop', 'myshop' ), array(
	'orders' => array(
		'label'       => __( 'Orders', 'myshop' ),
		'description' => __( 'Orders placed with this email address.', 'myshop' ),
		'find'        => fn ( string $email, int $page, int $per_page ): array => $orders->for_email( $email, $page, $per_page ),
		'fields'      => array(
			'number'            => __( 'Order number', 'myshop' ),
			'total'             => __( 'Total', 'myshop' ),
			'date_created'      => __( 'Date', 'myshop' ),
			'marketing_consent' => array( 'label' => __( 'Marketing emails', 'myshop' ), 'consent' => true ),
		),
		'item_id'     => 'id',
		'erase'       => fn ( string $email ): array => array(
			'removed'  => $orders->anonymise( $email ),
			'retained' => true,
		),
		'retained_message' => __( 'Orders are kept, without your details, as financial records.', 'myshop' ),
	),
) ) )->register();
```

`find` is given the address, the page (from 1) and the page size, and returns
that page's rows as arrays or objects; a short page is the last. `erase`
returns how many rows it `removed` and `retained` (or true and false),
`messages`, and `done` — false asks WordPress for another page.

## Only what you name

`fields` is an allow-list: a column added to the table next year stays out of
the export until somebody adds it here. A field is a label, or an array with a
`label` and either `value` (a callable given the row) or `consent`. Without
`fields`, every column is exported under its own name except those in
`redact`, which fails open — prefer the allow-list.

Columns match whatever their case. Booleans read Yes and No, a nested value is
JSON, and a field with nothing in it is left out rather than shown blank.

Another plugin, or an add-on, adds its groups through
`{id}_personal_data_groups`:

```php
add_filter( 'myshop_personal_data_groups', fn ( array $groups ): array => $groups + array(
	'licences' => array( 'label' => __( 'Licences', 'myshop-licences' ), 'find' => $find_licences ),
) );
```

## The queues

An erasure that cleans the tables and leaves a pending email or a webhook
waiting to retry is not one. They have no column to find the address by, so
they are searched as text:

```php
use SugarCommerce\Privacy\{Mentions, Redact};

$addresses = Mentions::keys( array( $email, ...$emails_on_their_orders ) );
$doomed    = array_filter( $outbox, fn ( $row ) => Mentions::any( $row['payload'], $addresses ) );

$order['delivery_error'] = Mentions::strip( $bounce );   // "550 <[address]>: mailbox full"
$rows                    = Redact::rows( $rows, 'access_token', 'email_hash' );
```

`Mentions::any()` looks for the address as typed and in each form
`json_encode()` writes it — `/` as `\/`, `é` as `é` — case-folded. It does
not normalise Unicode. `strip()` is looser than an address validator on
purpose: a false positive costs a word of an error message, a miss keeps an
address nobody can find to erase.

## The audit row

```php
use SugarCommerce\Privacy\{Pseudonym, Tally};

$subject = Pseudonym::for_address( $email );                    // core's wp_hash( $email, 'auth', 'sha256' )
$note    = Tally::note( array( 'orders' => 2, 'licences' => 1 ), 'ticket 4411' );
```

Sixteen hex characters: equal for the same person asking twice, meaningless
without the site's key. An unkeyed hash of an address is the address, to
anyone with a list of them, so `for_address()` refuses to run without a key
outside WordPress.

## What it does not do

Decide what an erasure keeps — an order is usually a financial record that
stays, without the person — or run it in a transaction. Run it all or
nothing: one that stops halfway leaves records half-anonymised and an audit row
saying it was done.

## Moving from the SugarCommerce SaaS package

From the retired `sugarcommerce/data-subject` (`SugarCommerce\DataSubject`).
Everything not listed keeps its name and behaviour under
`SugarCommerce\Privacy`.

| Old | New |
|---|---|
| `sugarcommerce/data-subject` | `sugarcommerce/privacy` |
| `SugarCommerce\DataSubject\*` | `SugarCommerce\Privacy\*` |
| `Tally::summary( $counts )` said `nothing held` | the same, in the site's language; `Tally::NOTHING` is still the English |
| `Pseudonym::subject( hash_hmac( 'sha256', $email, $key ) )` | `Pseudonym::for_address( $email, $key )`, or no key for the site's salt |
| `Consent::of( $v )->value` in an export | `Consent::of( $v )->label()` — translated; `value` and `cell()` unchanged |
| PHP 8.5 | PHP 8.3 |

New: `PersonalData`, which registers WordPress exporters and erasers from
arrays. Text domain `sugarcommerce`.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
