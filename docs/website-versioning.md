# Website versioning and Hostinger deployment

## Display
The website footer displays `© YYYY Metadata Berhad 1573083-A v0.0.0` at baseline.
- Source: root `VERSION` file
- Version reader: `includes/website_version.php`
- Footer: `includes/footer.php`

## Automatic patch increments
The GitHub Actions workflow `.github/workflows/website-version.yml` reacts to future pushes to `main`, increments the patch number using `scripts/bump-version.sh`, and pushes a second commit with `[skip-version]`. Version bootstrap commits and bot commits are skipped to avoid loops. Ensure Actions has permission to push to the branch; branch protection might require a different configuration.

This means a code push and a version update are **two separate commits**. If Hostinger is configured to auto-deploy each main push, it can run twice for a single code update; the second deployment should contain the new version.

## Hostinger setup
For custom PHP hosting, go to Hostinger hPanel → Websites → Manage site → Advanced → Git. Connect GitHub repository `aidilazharo/esports`, select branch `main`, select the deployment directory, and enable Auto-deployment. Check All deployments for status and current commit.

**Before enabling production auto-deploy**, protect `html/`, `download/` and `uploads/` from deployment-directory replacement or cleanup. These local-only folders are ignored by Git, but Git ignore rules do not control how Hostinger copies files onto the live server. Back up the directories and keep persistent uploads/downloads outside the replaceable application checkout, or use an explicit excludes-based deployment script and symlinks.

If auto-deployment is unavailable on your plan, use Hostinger's Redeploy button, or a carefully configured SSH-based deployment job after setting up durable data storage.
