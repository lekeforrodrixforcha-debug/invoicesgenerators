<?php
if (basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) === 'facturation-fr.php') {
    $scriptDirectory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    header('Location: ' . $scriptDirectory . '/facturation-cameroun', true, 301);
    exit;
}

require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/seo.php';

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$pageTitle = 'Générateur de factures au Cameroun';
$siteTitle = 'Générateur de factures au Cameroun (FCFA) | Invoices Generators';
$metaDescription = 'Créez gratuitement des factures en ligne au Cameroun. Choisissez le XAF (FCFA) ou le USD, personnalisez un modèle et téléchargez votre facture PDF.';
$seoPagePath = 'facturation-cameroun';
$seoLocale = 'fr_CM';
$htmlLang = 'fr';
$seoAlternates = [
    'en' => '',
    'fr-CM' => 'facturation-cameroun',
    'x-default' => '',
];
$seoStructuredData = seoLandingStructuredData($seoPagePath, $seoLocale, $siteTitle, $metaDescription, [
    'Puis-je créer une facture en FCFA au Cameroun ?' => 'Oui. Choisissez XAF (FCFA) comme devise de la facture, ajoutez les informations du client et téléchargez ou partagez le PDF.',
    'Puis-je télécharger ma facture en PDF ?' => 'Oui. Enregistrez votre facture dans votre espace, puis téléchargez ou partagez une version PDF prête à imprimer.',
    'Le générateur de factures est-il gratuit ?' => 'Oui. Le forfait Gratuit comprend jusqu’à cinq factures par mois, 25 clients et produits, deux modèles de facture et 10 demandes à l’assistant IA par mois.',
]);
include __DIR__ . '/includes/header.php';
?>

<main class="seo-local-page">
    <section class="seo-local-hero">
        <div class="container seo-local-hero-grid">
            <div class="seo-local-hero-copy">
                <span class="eyebrow"><span class="eyebrow-dot"></span>Pour les indépendants et les entreprises au Cameroun</span>
                <h1>Un générateur de factures simple, en FCFA ou en USD.</h1>
                <p>Créez une facture professionnelle en ligne, choisissez un modèle et téléchargez un PDF prêt à envoyer à votre client. Gérez aussi vos clients et retrouvez vos factures dans un seul espace.</p>
                <div class="button-row">
                    <a class="btn btn-primary" href="register.php">Créer ma première facture <span aria-hidden="true">→</span></a>
                    <a class="btn btn-text" href="#comment-ca-marche">Comment ça marche</a>
                </div>
                <p class="seo-local-hero-note">Commencez gratuitement. Aucun paiement n’est nécessaire pour créer un compte Gratuit.</p>
            </div>
            <aside class="seo-local-summary" aria-label="Résumé du forfait Gratuit">
                <span class="seo-local-summary-kicker">POUR COMMENCER</span>
                <strong>5 factures gratuites</strong>
                <ul>
                    <li>Factures en XAF (FCFA) ou USD</li>
                    <li>PDF à télécharger ou à partager</li>
                    <li>Jusqu’à 25 clients et produits</li>
                </ul>
                <a href="register.php">Ouvrir mon espace gratuit <span aria-hidden="true">↗</span></a>
            </aside>
        </div>
    </section>

    <section class="section seo-local-section" id="comment-ca-marche">
        <div class="container">
            <div class="section-header">
                <span class="eyebrow">Une facturation plus simple</span>
                <h2>Comment créer une facture en ligne</h2>
                <p>Préparez une facture lisible en quelques étapes, puis gardez-en une copie dans votre espace.</p>
            </div>
            <div class="grid-3 seo-local-steps">
                <article class="feature-card">
                    <span class="feature-index">01 / CLIENT</span>
                    <h3>Ajoutez les coordonnées</h3>
                    <p>Choisissez un client existant ou indiquez son nom et ses coordonnées sur la facture.</p>
                </article>
                <article class="feature-card">
                    <span class="feature-index">02 / DÉTAILS</span>
                    <h3>Décrivez le travail</h3>
                    <p>Ajoutez les prestations ou produits, les quantités, les prix et les dates utiles.</p>
                </article>
                <article class="feature-card">
                    <span class="feature-index">03 / PDF</span>
                    <h3>Téléchargez et partagez</h3>
                    <p>Vérifiez les totaux, enregistrez la facture et téléchargez un PDF à transmettre à votre client.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section seo-local-details">
        <div class="container seo-local-details-grid">
            <div>
                <span class="eyebrow">Pensé pour votre activité</span>
                <h2>Des factures adaptées à vos clients au Cameroun</h2>
                <p>Pour chaque facture, vous pouvez choisir la devise XAF (FCFA) ou USD, saisir vos lignes de facturation et ajouter les informations utiles à votre client. Les montants des factures restent associés à leur devise.</p>
                <p>Le forfait Gratuit permet de créer jusqu’à cinq factures par mois. Vous pouvez également enregistrer jusqu’à 25 clients et 25 produits, utiliser deux modèles et envoyer 10 demandes à l’assistant IA par mois.</p>
                <a class="workflow-link" href="register.php">Créer un compte Gratuit <span aria-hidden="true">↗</span></a>
            </div>
            <aside class="seo-local-checklist">
                <h3>Que mettre sur une facture ?</h3>
                <ul>
                    <li>Le nom et les coordonnées de votre entreprise</li>
                    <li>Le nom et les coordonnées du client</li>
                    <li>Un numéro de facture et une date d’émission</li>
                    <li>La description, la quantité et le prix de chaque prestation</li>
                    <li>La devise, les totaux et la date d’échéance</li>
                </ul>
                <p>Les obligations fiscales dépendent de votre situation. Vérifiez les règles applicables auprès d’un professionnel qualifié au Cameroun.</p>
            </aside>
        </div>
    </section>

    <section class="section faq-section seo-local-faq" id="questions">
        <div class="container">
            <div class="section-header">
                <span class="eyebrow">Questions fréquentes</span>
                <h2>À propos de la création de factures</h2>
            </div>
            <div class="faq-grid">
                <details class="faq-item">
                    <summary>Puis-je créer une facture en FCFA au Cameroun ?<span aria-hidden="true">+</span></summary>
                    <p>Oui. Choisissez XAF (FCFA) comme devise de la facture, ajoutez les informations du client et téléchargez ou partagez le PDF.</p>
                </details>
                <details class="faq-item">
                    <summary>Puis-je télécharger ma facture en PDF ?<span aria-hidden="true">+</span></summary>
                    <p>Oui. Enregistrez votre facture dans votre espace, puis téléchargez ou partagez une version PDF prête à imprimer.</p>
                </details>
                <details class="faq-item">
                    <summary>Le générateur de factures est-il gratuit ?<span aria-hidden="true">+</span></summary>
                    <p>Oui. Le forfait Gratuit comprend jusqu’à cinq factures par mois, 25 clients et produits, deux modèles de facture et 10 demandes à l’assistant IA par mois.</p>
                </details>
                <details class="faq-item">
                    <summary>Puis-je aussi facturer en dollars américains ?<span aria-hidden="true">+</span></summary>
                    <p>Oui. Sélectionnez USD comme devise sur une facture si vous devez facturer un client en dollars.</p>
                </details>
            </div>
        </div>
    </section>
</main>

<footer class="footer seo-local-footer">
    <div class="container">
        <a class="seo-local-language-link" href="index.php" lang="en">English: invoice generator for Cameroon</a>
    </div>
</footer>

<?php include __DIR__ . '/includes/footer.php'; ?>
