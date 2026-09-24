<?php

use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\Configuration\ClubController;
use App\Http\Controllers\Configuration\ClubLogoController;
use App\Http\Controllers\Configuration\DonationSettingsController;
use App\Http\Controllers\Configuration\MailSettingsController;
use App\Http\Controllers\Configuration\MemberFieldController;
use App\Http\Controllers\Configuration\SecurityAuditController;
use App\Http\Controllers\Configuration\SelfServiceSettingsController;
use App\Http\Controllers\Configuration\SystemController;
use App\Http\Controllers\Configuration\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Donations\DonationController;
use App\Http\Controllers\Forms\ReceiptController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Members\BulkUpdateMemberController;
use App\Http\Controllers\Members\MemberCardController;
use App\Http\Controllers\Members\MemberController;
use App\Http\Controllers\Members\MemberExportController;
use App\Http\Controllers\Members\MemberImportController;
use App\Http\Controllers\Members\MemberIndexController;
use App\Http\Controllers\Members\MembershipApplicationController;
use App\Http\Controllers\Members\PostalCodeController;
use App\Http\Controllers\Payments\BankImportController;
use App\Http\Controllers\Payments\ContributionController;
use App\Http\Controllers\Payments\InvoiceController;
use App\Http\Controllers\Payments\MandateExportController;
use App\Http\Controllers\Payments\ManualPaymentController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\Payments\ReturnDebitController;
use App\Http\Controllers\Payments\SepaExportController;
use App\Http\Controllers\SelfService\AccessController;
use App\Http\Controllers\SelfService\PortalController;
use App\Http\Controllers\StatisticsController;
use App\Http\Middleware\EnsureSelfService;
use App\Models\ClubSetting;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('install', [InstallController::class, 'create'])->name('install.create');
Route::post('install', [InstallController::class, 'store'])->middleware('throttle:5,1')->name('install.store');

Route::get('/', fn () => Inertia::render('Welcome', ['selfserviceEnabled' => (bool) (ClubSetting::current()->data['selfservice_enabled'] ?? false), 'publicJoinEnabled' => (bool) (ClubSetting::current()->data['public_join_enabled'] ?? false)]))->name('home');
Route::get('branding/logo', [ClubLogoController::class, 'show'])->name('branding.logo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::inertia('ueber-gymslunity', 'About')->name('about');
    Route::middleware('can:view-communication')->prefix('kommunikation')->group(function () {
        Route::get('/', [CommunicationController::class, 'index'])->defaults('tab', 'mail')->name('kommunikation');
        Route::get('serienmails', [CommunicationController::class, 'index'])->defaults('tab', 'mail')->name('communication.mail');
        Route::get('serienbriefe', [CommunicationController::class, 'index'])->defaults('tab', 'letters')->name('communication.letters');
        Route::get('verlauf', [CommunicationController::class, 'index'])->defaults('tab', 'history')->name('communication.history');
        Route::post('serienmails', [CommunicationController::class, 'sendMail'])->middleware('throttle:2,1')->name('communication.mail.send');
        Route::post('serienbriefe', [CommunicationController::class, 'generateLetters'])->middleware(['throttle:10,1', 'audit:data_export'])->name('communication.letters.generate');
    });
    Route::middleware('can:view-payments')->prefix('beitraege')->group(function () {
        Route::get('/', PaymentController::class)->defaults('tab', 'overview')->name('payments');
        Route::get('mandate', PaymentController::class)->defaults('tab', 'mandates')->name('payments.mandates.index');
        Route::get('anlegen', PaymentController::class)->defaults('tab', 'create')->name('payments.create');
        Route::get('rechnungen', PaymentController::class)->defaults('tab', 'invoices')->name('payments.invoices.index');
        Route::get('sepa-export', PaymentController::class)->defaults('tab', 'sepa')->name('payments.sepa.index');
        Route::get('bankimport', PaymentController::class)->defaults('tab', 'bank')->name('payments.bank-import.index');
        Route::get('ruecklastschriften', PaymentController::class)->defaults('tab', 'returns')->name('payments.return-debits.index');
        Route::get('manuell-buchen', PaymentController::class)->defaults('tab', 'manual')->name('payments.manual.index');
        Route::post('anlegen', [ContributionController::class, 'store'])->name('payments.contributions.store');
        Route::get('mandate/export', MandateExportController::class)->middleware(['throttle:sensitive', 'audit:data_export'])->name('payments.mandates.export');
        Route::post('rechnungen/erzeugen', [InvoiceController::class, 'generate'])->name('payments.invoices.generate');
        Route::post('rechnungen/versenden', [InvoiceController::class, 'send'])->name('payments.invoices.send');
        Route::get('rechnungen/{contribution}', [InvoiceController::class, 'document'])->middleware(['throttle:sensitive', 'audit:document_access'])->name('payments.invoices.document');
        Route::post('sepa-export', SepaExportController::class)->middleware(['throttle:sensitive', 'audit:data_export'])->name('payments.sepa.export');
        Route::post('bankimport', BankImportController::class)->name('payments.bank-import');
        Route::post('ruecklastschriften', ReturnDebitController::class)->name('payments.return-debits.store');
        Route::post('manuell-buchen', ManualPaymentController::class)->name('payments.manual.store');
    });
    Route::middleware('can:view-statistics')->prefix('auswertungen')->group(function () {
        Route::get('/', [StatisticsController::class, 'index'])->defaults('tab', 'overview')->name('statistics');
        Route::get('mitglieder', [StatisticsController::class, 'index'])->defaults('tab', 'members')->name('statistics.members');
        Route::get('finanzen', [StatisticsController::class, 'index'])->defaults('tab', 'finances')->name('statistics.finances');
        Route::get('datenqualitaet', [StatisticsController::class, 'index'])->defaults('tab', 'quality')->name('statistics.quality');
        Route::get('bestandsmeldung.csv', [StatisticsController::class, 'stockCsv'])->middleware(['throttle:sensitive', 'audit:data_export'])->name('statistics.stock-csv');
    });
    Route::inertia('buchhaltung', 'Finance')->middleware('can:view-finance')->name('finance');
    Route::inertia('formulare', 'Forms')->middleware('can:view-forms')->name('forms');
    Route::middleware('can:view-forms')->prefix('formulare/quittungen')->name('receipts.')->group(function () {
        Route::get('/', [ReceiptController::class, 'index'])->defaults('tab', 'create')->name('index');
        Route::get('archiv', [ReceiptController::class, 'index'])->defaults('tab', 'list')->name('archive');
        Route::post('/', [ReceiptController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::get('{receipt}', [ReceiptController::class, 'show'])->name('show');
        Route::get('{receipt}/pdf/{edition}', [ReceiptController::class, 'document'])->middleware(['throttle:sensitive', 'audit:document_access'])->name('document');
        Route::post('{receipt}/versenden', [ReceiptController::class, 'send'])->middleware('throttle:5,1')->name('send');
    });
    Route::middleware('can:view-donations')->prefix('spenden')->group(function () {
        Route::get('/', [DonationController::class, 'index'])->defaults('tab', 'ledger')->name('donations');
        Route::get('anlegen', [DonationController::class, 'index'])->defaults('tab', 'create')->name('donations.create');
        Route::get('offene-bestaetigungen', [DonationController::class, 'index'])->defaults('tab', 'open')->name('donations.open');
        Route::post('/', [DonationController::class, 'store'])->name('donations.store');
        Route::post('{donation}/ausstellen', [DonationController::class, 'issue'])->name('donations.certificates.issue');
        Route::get('bestaetigungen/{certificate}', [DonationController::class, 'document'])->middleware(['throttle:sensitive', 'audit:document_access'])->name('donations.certificates.document');
        Route::post('bestaetigungen/{certificate}/versenden', [DonationController::class, 'send'])->name('donations.certificates.send');
    });
    Route::middleware('can:view-inventory')->prefix('inventar')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->defaults('tab', 'overview')->name('inventory');
        Route::get('inventarisieren', [InventoryController::class, 'index'])->defaults('tab', 'create')->name('inventory.create');
        Route::post('/', [InventoryController::class, 'store'])->name('inventory.store');
        Route::patch('{inventoryItem:inventory_number}/abgang', [InventoryController::class, 'dispose'])->name('inventory.dispose');
    });
    Route::get('ortsangaben', PostalCodeController::class)->middleware('throttle:60,1')->name('postal.lookup');
    Route::get('mitglieder', MemberIndexController::class)->name('members.index');
    Route::get('mitglieder/antraege', [MembershipApplicationController::class, 'index'])->name('members.applications.index');
    Route::post('mitglieder/{member:member_number}/beitritt-freigeben', [MembershipApplicationController::class, 'approve'])->name('members.applications.approve');
    Route::get('mitglieder/anlegen', [MemberController::class, 'create'])->name('members.create');
    Route::post('mitglieder/anlegen', [MemberController::class, 'store'])->name('members.store');
    Route::get('mitglieder/importieren', [MemberImportController::class, 'index'])->name('members.import.index');
    Route::get('mitglieder/importieren/vorlage', [MemberImportController::class, 'template'])->middleware(['throttle:sensitive', 'audit:data_export'])->name('members.import.template');
    Route::post('mitglieder/importieren/vorschau', [MemberImportController::class, 'preview'])->name('members.import.preview');
    Route::post('mitglieder/importieren/zuordnen', [MemberImportController::class, 'map'])->name('members.import.map');
    Route::post('mitglieder/importieren/abschliessen', [MemberImportController::class, 'store'])->name('members.import.store');
    Route::post('mitglieder/export', MemberExportController::class)->middleware(['throttle:20,1', 'audit:data_export'])->name('members.export');
    Route::patch('mitglieder/massenbearbeitung', BulkUpdateMemberController::class)->middleware('throttle:10,1')->name('members.bulk-update');
    Route::get('mitglieder/{member:member_number}/karteiblatt', MemberCardController::class)->middleware(['throttle:sensitive', 'audit:document_access'])->name('members.card');
    Route::get('mitglieder/{member:member_number}', [MemberController::class, 'show'])->name('members.show');
    Route::patch('mitglieder/{member:member_number}', [MemberController::class, 'update'])->name('members.update');
    Route::post('mitglieder/{member:member_number}/dokumente/{kind}', [MemberController::class, 'storeDocument'])->name('members.documents.store');
    Route::get('mitglieder/{member:member_number}/dokumente/{kind}', [MemberController::class, 'document'])->middleware(['throttle:sensitive', 'audit:document_access'])->name('members.document');
    Route::middleware('can:manage-configuration')->prefix('konfiguration')->name('configuration.')->group(function () {
        Route::redirect('/', '/konfiguration/verein');
        Route::get('verein', [ClubController::class, 'edit'])->name('club.edit');
        Route::patch('verein', [ClubController::class, 'update'])->name('club.update');
        Route::post('verein/logo', [ClubLogoController::class, 'store'])->name('club.logo.store');
        Route::delete('verein/logo', [ClubLogoController::class, 'destroy'])->name('club.logo.destroy');
        Route::get('mitgliedsfelder', [MemberFieldController::class, 'index'])->name('fields.index');
        Route::post('mitgliedsfelder', [MemberFieldController::class, 'store'])->name('fields.store');
        Route::patch('mitgliedsfelder/reihenfolge', [MemberFieldController::class, 'reorder'])->name('fields.reorder');
        Route::patch('mitgliedsfelder/{field}', [MemberFieldController::class, 'update'])->name('fields.update');
        Route::get('benutzer', [UserController::class, 'index'])->name('users.index');
        Route::post('benutzer', [UserController::class, 'store'])->name('users.store');
        Route::patch('benutzer/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('email', [MailSettingsController::class, 'edit'])->name('mail.edit');
        Route::patch('email', [MailSettingsController::class, 'update'])->name('mail.update');
        Route::post('email/test', [MailSettingsController::class, 'test'])->middleware('throttle:5,1')->name('mail.test');
        Route::get('selfservice', [SelfServiceSettingsController::class, 'edit'])->name('selfservice.edit');
        Route::patch('selfservice', [SelfServiceSettingsController::class, 'update'])->name('selfservice.update');
        Route::get('spenden', [DonationSettingsController::class, 'edit'])->name('donations.edit');
        Route::patch('spenden', [DonationSettingsController::class, 'update'])->name('donations.update');
        Route::get('system', SystemController::class)->name('system');
        Route::get('sicherheitsprotokoll', SecurityAuditController::class)->name('security-audit');
    });
});

require __DIR__.'/settings.php';

Route::middleware(EnsureSelfService::class)->prefix('selfservice')->group(function () {
    Route::get('zugang', [AccessController::class, 'index']);
    Route::post('zugang/anfordern', [AccessController::class, 'request'])->middleware('throttle:10,1');
    Route::post('zugang/bestaetigen', [AccessController::class, 'consume'])->middleware('throttle:10,1');
    Route::post('abmelden', [AccessController::class, 'logout']);
    Route::get('/', [PortalController::class, 'index']);
    Route::patch('profil', [PortalController::class, 'update'])->middleware('throttle:20,1');
    Route::get('beitritt', [PortalController::class, 'form'])->defaults('kind', 'application');
    Route::get('mandat', [PortalController::class, 'form'])->defaults('kind', 'sepa');
    Route::post('formulare/{kind}', [PortalController::class, 'submit'])->middleware('throttle:5,1');
    Route::get('dokumente/{kind}', [PortalController::class, 'document'])->middleware(['throttle:30,1', 'audit:document_access']);
});
