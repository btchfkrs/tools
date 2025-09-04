# Tools Repository

This repository is a collection of troubleshooting tools designed to assist with diagnosing and resolving issues related to various integrations and protocols, including SMPP, SSL, and others. Each tool is organized in its own dedicated folder, complete with an extended README providing detailed usage instructions, prerequisites, and examples.

## Purpose
The purpose of this repository is to provide a centralized location for utilities that streamline the process of identifying and fixing issues in communication protocols and integrations. These tools are intended for developers, system administrators, and engineers working with complex systems.

## Repository Structure
- Each tool resides in its own folder, named descriptively to indicate its purpose or the protocol/integration it addresses.
- Inside each folder, you will find:
  - The tool's source code or executable files.
  - An extended `README.md` with specific instructions, including:
    - Tool description
    - Installation and setup
    - Usage examples
    - Dependencies
    - Troubleshooting tips
- The tools are designed to be independent, allowing you to use only the ones relevant to your needs.
- Language of the tools is not limited to PHP, as some tools can also be Shell, Python, or JavaScript.

## Available Tools
Below is a list of tools currently included in this repository (to be updated as new tools are added):

- **SMPP Checker** (e.g., `smpp-checker`): A minimal standalone PHP CLI tool to verify SMPP connectivity and bind status against an SMSC.
- (Will extend list with more tools as they added to the repository)

## Getting Started
1. **Clone the Repository**:
   ```bash
   git clone https://github.com/btchfkrs/tools.git
   ```
2. Navigate to the desired tool's folder:
   ```bash
   cd tools/<tool-folder>
   ```
3. Follow the instructions in the tool's `README.md` for setup and usage.

## Contributing
Contributions are welcome! If you have a tool or improvement to share:
1. Fork this repository.
2. Create a new folder for your tool, including its source code and a detailed `README.md`.
3. Submit a pull request with a clear description of your changes.

Please ensure your tool aligns with the repository's purpose and includes documentation.

## License
This repository is licensed under the [GNU General Public License v2.0](LICENSE). See the `LICENSE` file for details.

## Contact
For questions or suggestions, please open an issue.