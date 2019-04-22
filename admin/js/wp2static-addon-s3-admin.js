(function( $ ) {
	'use strict';

	$(function() {
    deploy_options['github'] = {
      exportSteps: [
          'github_prepare_export',
          'github_transfer_files',
          'cloudfront_invalidate_all_items',
          'finalize_deployment'
      ],
      required_fields: {
        githubKey: 'Please input an GitHub Key in order to authenticate when using the GitHub deployment method.',
        githubSecret: 'Please input an GitHub Secret in order to authenticate when using the GitHub deployment method.',
        githubBucket: 'Please input the name of the GitHub bucket you are trying to deploy to.',
      }
    };

    status_descriptions['github_prepare_export'] = 'Preparing files for GitHub deployment';
    status_descriptions['github_transfer_files'] = 'Deploying files to GitHub';
    status_descriptions['cloudfront_invalidate_all_items'] = 'Invalidating CloudFront cache';
  }); // end DOM ready

})( jQuery );
