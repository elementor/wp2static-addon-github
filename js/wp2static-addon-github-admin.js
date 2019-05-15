(function( $ ) {
	'use strict';

	$(function() {
    deploy_options['github'] = {
      exportSteps: [
          'github_prepare_export',
          'github_transfer_files',
          'finalize_deployment'
      ],
      required_fields: {
        ghBranch: 'Please input an GitHub Branch in order to authenticate when using the GitHub deployment method.',
        ghToken: 'Please input an GitHub Token in order to authenticate when using the GitHub deployment method.',
        ghRepo: 'Please input the name of the GitHub Repo you are trying to deploy to.',
      }
    };

    status_descriptions['github_prepare_export'] = 'Preparing files for GitHub deployment';
    status_descriptions['github_transfer_files'] = 'Deploying files to GitHub';
  }); // end DOM ready

})( jQuery );
