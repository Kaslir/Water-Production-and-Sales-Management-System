using System;
using System.Diagnostics;
using System.IO;

internal static class AquaTrackLauncher
{
    private static int Main(string[] args)
    {
        string projectDirectory = Path.GetDirectoryName(typeof(AquaTrackLauncher).Assembly.Location);
        string script = Path.Combine(projectDirectory, "Start-AquaTrack.ps1");
        if (!File.Exists(script))
        {
            Console.Error.WriteLine("AquaTrack launcher script is missing: " + script);
            WaitForUser();
            return 1;
        }

        string powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),
            @"WindowsPowerShell\v1.0\powershell.exe");
        bool noBrowser = Array.Exists(args, item => item.Equals("/NoBrowser", StringComparison.OrdinalIgnoreCase));
        string arguments = "-NoProfile -ExecutionPolicy Bypass -File \"" + script + "\"";
        if (noBrowser)
        {
            arguments += " -NoBrowser";
        }

        try
        {
            using (Process process = new Process())
            {
                process.StartInfo = new ProcessStartInfo(powershell, arguments)
                {
                    UseShellExecute = false,
                    WorkingDirectory = projectDirectory
                };
                process.Start();
                process.WaitForExit();
                if (process.ExitCode != 0)
                {
                    Console.Error.WriteLine("AquaTrack did not start. Review the message above.");
                    WaitForUser();
                }
                return process.ExitCode;
            }
        }
        catch (Exception error)
        {
            Console.Error.WriteLine("AquaTrack could not start: " + error.Message);
            WaitForUser();
            return 1;
        }
    }

    private static void WaitForUser()
    {
        if (!Console.IsInputRedirected)
        {
            Console.WriteLine("Press Enter to close this window.");
            Console.ReadLine();
        }
    }
}
