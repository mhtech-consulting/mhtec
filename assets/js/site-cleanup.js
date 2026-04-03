(function () {
    function setHref(selector, href) {
        document.querySelectorAll(selector).forEach(function (link) {
            link.setAttribute("href", href);
        });
    }

    function applyLegalLinks() {
        setHref('a[data-i18n="footer.terms"]', "terms-of-use.html");
        setHref('a[data-i18n="footer.privacy"]', "privacy-policy.html");
        setHref('a[data-i18n="about_page.footer_terms"]', "terms-of-use.html");
        setHref('a[data-i18n="about_page.footer_privacy"]', "privacy-policy.html");
    }

    function normalizeTemplateLinks() {
        setHref('a[href="pricing.html"]', "contact.html#contact-form");
        setHref('a[href="project-details.html"]', "projects.html");
        setHref('a[href="team-details.html"]', "about.html");
    }

    function normalizeFooterPostLinks() {
        setHref('a[data-i18n="about_page.footer_post1_title"]', "blog.html");
        setHref('a[data-i18n="about_page.footer_post2_title"]', "blog.html");
    }

    function applyLocationFallback() {
        document.querySelectorAll('[data-i18n="footer.location"], [data-i18n="about_page.footer_location"]').forEach(function (node) {
            if (!node.textContent.trim() || node.textContent.indexOf("Votre localisation") !== -1) {
                node.textContent = "A distance, sur rendez-vous";
            }
        });
    }

    function runCleanup() {
        applyLegalLinks();
        normalizeTemplateLinks();
        normalizeFooterPostLinks();
        applyLocationFallback();
    }

    document.addEventListener("DOMContentLoaded", runCleanup);
    window.addEventListener("load", runCleanup);
    window.addEventListener("mhtech:langchange", runCleanup);
})();
